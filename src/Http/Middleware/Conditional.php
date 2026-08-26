<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Http\Middleware;

use Closure;
use DateTimeImmutable;
use ExpertSystems\ConditionalRequests\ConditionalRequests;
use ExpertSystems\ConditionalRequests\Contracts\LockableValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\RequestValidatorStrategy;
use ExpertSystems\ConditionalRequests\Contracts\ValidatorStrategy;
use ExpertSystems\ConditionalRequests\Exceptions\LockTimeoutException;
use ExpertSystems\ConditionalRequests\Exceptions\PreconditionFailedException;
use ExpertSystems\ConditionalRequests\Exceptions\PreconditionRequiredException;
use ExpertSystems\ConditionalRequests\Locking\LockWait;
use ExpertSystems\ConditionalRequests\Preconditions\PreconditionEvaluator;
use ExpertSystems\ConditionalRequests\Preconditions\PreconditionOutcome;
use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use LogicException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Handles RFC 9110 conditional requests for a route.
 */
final readonly class Conditional
{
    /**
     * @internal The constructor is resolved from the container and is not part
     *           of the frozen surface — it gained parameters in three
     *           consecutive releases and may gain more. See docs/api.md.
     */
    public function __construct(
        private ConditionalRequests $registry,
        private Repository $config,
        private PreconditionEvaluator $evaluator,
        private Translator $translator,
        private LockWait $lockWait,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string ...$flags): Response
    {
        // array_values() looks like a no-op and is one at runtime, but PHPStan
        // types a `string ...$flags` variadic as array<int<0,max>|string,
        // string> — a variadic can receive named arguments — so it is needed
        // to satisfy the list<string> parameter below. Do not remove it.
        $parsed = Flags::parse(array_values($flags));

        // RFC 9110 §13 splits here and the two halves never mix: an unsafe
        // method is guarded before the controller runs and never receives a
        // validator of its own, and a safe one takes the read path exactly as
        // it did in v0.1 and v0.2. isMethodSafe() is Symfony's own list —
        // GET, HEAD, OPTIONS, TRACE, and the draft QUERY method.
        if (! $request->isMethodSafe()) {
            return $this->write($request, $next, $parsed);
        }

        // Everything that can be decided from the request alone is decided here,
        // before the request is touched, so `enabled => false` really is a
        // pass-through and the controller sees the method the client sent.
        if (! $this->requestEligible($request)) {
            return $next($request);
        }

        $strategy = $this->registry->strategy($this->strategyName($parsed));

        // A strategy that answers from the request alone changes two things: the
        // validator is known before the controller runs, and the rendered body
        // is never needed for it. Both key off the validator actually produced
        // rather than off the interface — implementing the contract is a
        // declaration, and a strategy that declines on this particular request
        // falls back to fromResponse() and needs the body exactly as a
        // body-derived one does. See eligible(), and $mutate below.
        $validator = $strategy instanceof RequestValidatorStrategy
            ? $strategy->fromRequest($request)
            : null;

        if ($validator instanceof Validator) {
            $notModified = $this->notModified($request, $validator);

            if ($notModified instanceof Response) {
                return $notModified;
            }
        }

        $originalMethod = $request->getMethod();
        $isHead = $originalMethod === 'HEAD';

        // Never before routing. Under kernel-global placement the mutation
        // would land ahead of the router, which would then go looking for a
        // GET route: a route registered for HEAD alone answers 405, and a HEAD
        // to a URI carrying both actions reaches the GET one. A middleware must
        // not change what a request routes to, so the body hash is what gives
        // way out here — Router::prepareResponse() empties the body for the
        // HEAD it can now see and BodyHashStrategy declines to hash an empty
        // one, leaving the response untagged. That is the degradation `model`
        // already takes at this position.
        $mutate = $isHead && ! ($validator instanceof Validator) && $request->route() !== null;

        // Router::runRouteWithinStack()'s pipeline destination is
        // prepareResponse() (Router.php:821), which runs before route middleware
        // regains control, and Symfony's Response::prepare() nulls the body for
        // HEAD there. Present the request to the controller as a GET so there is
        // a body left to hash, then re-empty the response ourselves afterwards.
        if ($mutate) {
            $request->setMethod('GET');
        }

        try {
            $response = $next($request);
        } finally {
            if ($mutate) {
                $request->setMethod($originalMethod);
            }
        }

        // The exclusion is evaluated a second time on purpose. Under kernel-global
        // placement the first check ran before routing, where $request->route() is
        // null and Request::routeIs() answers false for every pattern, so a
        // route-name exclusion could only ever be honoured here. The pre-$next()
        // check still earns its place: it keeps a URI-excluded route a true
        // pass-through with no request mutation at all.
        if (! $this->excluded($request) && $this->eligible($response, $validator instanceof Validator)) {
            $this->attach($request, $response, $strategy, $validator);
        }

        // Single exit, so every path applies the HEAD nulling. Belt and braces
        // against the router, which empties a HEAD body itself in
        // Router::runRoute()'s prepareResponse (Router.php:799) — but that runs
        // after every route middleware, and this runs before them, so nothing
        // declared outside `conditional` ever sees a HEAD response still
        // carrying the body the controller wrote.
        return $isHead ? $this->withoutBody($request, $response) : $response;
    }

    /**
     * Guard an unsafe request against the resource's current validator.
     *
     * Everything here happens before $next(). The point of the write path is to
     * refuse a write that would clobber someone else's, and a refusal issued
     * after the controller has run is not a refusal — the update is already
     * lost. Nothing downstream ever sees a request whose precondition failed.
     *
     * @param  Closure(Request): Response  $next
     */
    private function write(Request $request, Closure $next, Flags $flags): Response
    {
        // The write path checks the exclusions once, not twice as the read path
        // does. It has no second chance by construction: the decision must
        // precede the controller, so a route-name exclusion cannot be honoured
        // under kernel-global placement, where nothing has been routed yet.
        if (! $this->enabled() || $this->excluded($request)) {
            return $next($request);
        }

        $name = $this->strategyName($flags);
        $strategy = $this->registry->strategy($name);

        // Checked ahead of the request-derived guard below because
        // LockableValidatorStrategy extends RequestValidatorStrategy: a `lock`
        // route whose strategy is neither fails both, and this is the error
        // that names everything wrong with it in one pass.
        //
        // The narrowed strategy is kept in its own variable rather than left
        // for the call to locked() to re-derive from $flags->lock. Proving that
        // `$flags->lock` implies `$strategy instanceof LockableValidatorStrategy`
        // two hundred lines further down requires the analyser to carry this
        // throw's condition through everything in between, which PHPStan does
        // only from 2.2 onward — larastan ^3.9 resolves 2.1.32 under
        // `prefer-lowest`, where the call was reported as passing a plain
        // RequestValidatorStrategy. A nullable local needs no such inference on
        // any version, and re-testing $strategy here instead would be reported
        // as an already-narrowed type on 2.2. Non-null exactly when the flag is
        // set and the guard passed.
        $lockable = null;

        if ($flags->lock) {
            if (! $strategy instanceof LockableValidatorStrategy) {
                throw $this->lockableStrategyError($request, $name);
            }

            $lockable = $strategy;
        }

        if (! $strategy instanceof RequestValidatorStrategy) {
            if ($flags->required) {
                throw $this->guardStrategyError($request, $name);
            }

            // A body hash describes a response that does not exist yet, so
            // there is nothing to compare and nothing this path can guard.
            //
            // A request that sent no precondition passes through unchanged —
            // the guard is opt-in, and `body` is the default strategy, so a
            // plain `conditional` write route has to keep behaving as it did.
            // A request that did send one cannot: it asked for a guarantee
            // this route cannot provide, and discarding the header silently is
            // the exact failure this package exists to prevent — the route
            // looks guarded, answers 200, and the client believes its
            // optimistic-concurrency check was honoured. Fail closed instead.
            //
            // Only once there is a route to speak for. Under kernel-global
            // placement this runs ahead of the router: there are no flags to
            // read, so strategyName() can only return the configured default —
            // `body` out of the box — and refusing here would refuse on behalf
            // of a route that has not been chosen yet, including the
            // `conditional:required` one whose own guard would then never run.
            // Every guarded write in the application would answer 412, the
            // ones carrying the correct If-Match among them: the inverted
            // guard this refusal exists to prevent, applied globally. The
            // global instance defers and the route-level one decides. Under
            // route or group placement the route is resolved, the strategy
            // named here is the one that will actually be asked, and a
            // precondition it cannot evaluate is still refused.
            if ($request->route() !== null && $this->evaluator->supplied($request)) {
                throw new PreconditionFailedException(
                    $this->message(PreconditionFailedException::MESSAGE_KEY),
                );
            }

            return $next($request);
        }

        // Asked before the validator, and only here: the read path never puts
        // this question to a strategy. It is what keeps "absent" apart from
        // "present but silent" for the create guard below.
        $exists = $strategy->targetExists($request);
        $current = $strategy->fromRequest($request);

        // Not gated on `required`. Weakness inverts the guard wherever an
        // If-Match reaches it: §13.1.1 requires strong comparison, so every
        // client sending the correct token is refused with 412 while every
        // client sending nothing writes freely — the exact opposite of what the
        // route was asked to do, with nothing in either response to say why.
        // The `required` flag is the second trigger rather than the only one,
        // because there the misconfiguration is fatal before a client sends
        // anything at all. A write carrying no precondition on a route without
        // the flag is guarded by nothing and still passes.
        if ($current instanceof Validator && $current->weak && ($flags->required || $this->evaluator->sentIfMatch($request))) {
            throw new LogicException(sprintf(
                '[%s] is guarded against a weak validator produced by the [%s] strategy. RFC 9110 §13.1.1 '
                .'requires strong comparison for If-Match, so a weak validator can never satisfy one: every '
                .'write naming the current version is refused with 412, and every write naming nothing is '
                .'applied unguarded. Set [laravel-conditional-requests.weak] to false, or take the conditional '
                .'middleware off this write route.',
                $this->label($request),
                $name,
            ));
        }

        // Evaluated here as well as under the lock, so a request that is
        // already doomed is refused without opening a transaction and without
        // queueing behind a row lock. The evaluation that is load-bearing for
        // correctness is the one inside locked(); this one is a cheap filter.
        $refusal = $this->refusal($this->evaluator->evaluate($request, $current, $flags->required, $exists));

        if ($refusal instanceof HttpException) {
            throw $refusal;
        }

        // $lockable is non-null exactly when $flags->lock is set, because the
        // guard at the top of this method throws for a `lock` route whose
        // strategy is not lockable. Branching on it rather than on the flag is
        // what keeps the type provable here without a second instanceof.
        return $lockable instanceof LockableValidatorStrategy
            ? $this->locked($request, $next, $lockable, $current, $flags->required)
            : $next($request);
    }

    /**
     * Re-check the precondition against a locked row, then run the controller
     * inside the same transaction.
     *
     * This is the whole of `lock` mode and the whole of design D1. If-Match on
     * its own is check-then-write: the guard reads the current validator, and
     * between that read and the controller's write another request can commit.
     * Re-reading the row under SELECT … FOR UPDATE and evaluating the
     * precondition a second time against what comes back closes that window,
     * because from the lock until commit nothing else can change the row.
     *
     * Acquiring the lock without the second evaluation would preserve the race
     * with extra steps and extra cost, which is why the two lines below are
     * inseparable.
     *
     * @param  Closure(Request): Response  $next
     */
    private function locked(
        Request $request,
        Closure $next,
        LockableValidatorStrategy $strategy,
        ?Validator $current,
        bool $required,
    ): Response {
        $target = $strategy->lockTarget($request);

        if (! $target instanceof Model) {
            // Two very different situations collapse to the same null, and the
            // validator already in hand tells them apart. A resource that
            // exists but is not a row is §5.5's non-database resource — a
            // wiring error, because `lock` cannot mean anything for it. No
            // resource at all is an ordinary create, whose protection is
            // If-None-Match: * plus a unique constraint; a row lock has nothing
            // to hold and wrapping the create in a transaction would buy
            // nothing while inheriting every hazard in §5.5.
            if ($current instanceof Validator) {
                throw $this->unlockableTargetError($request);
            }

            return $next($request);
        }

        $connection = $target->getConnection();

        try {
            return $this->lockWait->transaction(
                $connection,
                (int) $this->config->get('laravel-conditional-requests.lock_timeout'),
                function () use ($request, $next, $strategy, $target, $required): Response {
                    $strategy->lockAndRefresh($request, $target);

                    // Existence is re-asked along with the validator: a record
                    // deleted since it was bound is forgotten from the route by
                    // lockAndRefresh(), and the create guard has to see that
                    // rather than the answer from before the lock.
                    $refusal = $this->refusal($this->evaluator->evaluate(
                        $request,
                        $strategy->fromRequest($request),
                        $required,
                        $strategy->targetExists($request),
                    ));

                    if ($refusal instanceof HttpException) {
                        // Connection::transaction() with its default of one
                        // attempt rolls back and rethrows unchanged, so a 412
                        // raised in here reaches the handler as the same 412 it
                        // would have been outside — never a 500.
                        throw $refusal;
                    }

                    return $next($request);
                },
            );
        } catch (Throwable $exception) {
            if (! $this->lockWait->caused($exception)) {
                throw $exception;
            }

            throw new LockTimeoutException($this->message(LockTimeoutException::MESSAGE_KEY), $exception);
        }
    }

    /**
     * The exception an outcome deserves, or null when it passed.
     *
     * Shared by the evaluation before the lock and the one inside it, so the
     * two cannot drift and a refusal issued under a lock is indistinguishable
     * from one issued without.
     */
    private function refusal(PreconditionOutcome $outcome): ?HttpException
    {
        return match ($outcome) {
            PreconditionOutcome::Passed => null,
            PreconditionOutcome::Failed => new PreconditionFailedException(
                $this->message(PreconditionFailedException::MESSAGE_KEY),
            ),
            PreconditionOutcome::Required => new PreconditionRequiredException(
                $this->message(PreconditionRequiredException::MESSAGE_KEY),
            ),
        };
    }

    /**
     * The route asked for a guard that has to answer before the controller
     * runs, and named a strategy that cannot.
     *
     * v0.3's message, moved into a method and otherwise unchanged. Only
     * `required` can reach it: the `lock` check above throws first, and
     * LockableValidatorStrategy extends the interface this one tests for.
     */
    private function guardStrategyError(Request $request, string $name): LogicException
    {
        return new LogicException(sprintf(
            '[%s] is guarded by the conditional `required` flag, but the [%s] validator strategy '
            .'cannot produce a validator before the controller runs, so every guarded write would '
            .'be refused with 412. Drop the explicit strategy flag — `required` already implies '
            .'`model` — or name a strategy implementing %s.',
            $this->label($request),
            $name,
            RequestValidatorStrategy::class,
        ));
    }

    /**
     * The route asked to serialise on a row and the strategy cannot say which.
     */
    private function lockableStrategyError(Request $request, string $name): LogicException
    {
        return new LogicException(sprintf(
            '[%s] is flagged `lock`, but the [%s] validator strategy cannot name a row to lock, so the '
            .'transaction would serialise on nothing and the check-then-write race the flag exists to '
            .'close would still be open. Drop the explicit strategy flag — `lock` already implies '
            .'`model` — or name a strategy implementing %s.',
            $this->label($request),
            $name,
            LockableValidatorStrategy::class,
        ));
    }

    /**
     * The strategy can name rows, and this resource is not one — design §5.5's
     * non-database resource.
     */
    private function unlockableTargetError(Request $request): LogicException
    {
        return new LogicException(sprintf(
            '[%s] is flagged `lock` and its resource reports a validator, but that resource is not an '
            .'Eloquent model, so there is no row to lock and `lock` cannot mean anything for it. Remove '
            .'the `lock` flag from this route, or bind a resource the strategy can lock.',
            $this->label($request),
        ));
    }

    /**
     * Attach a validator and let Symfony decide whether it is still current.
     *
     * A validator already computed from the request is reused rather than
     * derived a second time: it is the same answer, and reusing it guarantees
     * the tag on this response is the one the short-circuit will compare
     * against on the next request.
     */
    private function attach(Request $request, Response $response, ValidatorStrategy $strategy, ?Validator $known): void
    {
        $validator = $known ?? $strategy->fromResponse($request, $response);

        if (! $validator instanceof Validator) {
            return;
        }

        $response->setEtag($validator->etag, $validator->weak);
        $this->attachLastModified($response, $validator);

        // Symfony performs the RFC 9110 comparison and, on a match, mutates the
        // response into a compliant 304 — status, empty body, stripped headers.
        if ($response->isNotModified($request)) {
            $this->complete($request, $response);
        }
    }

    /**
     * Publish the validator's modification date without changing what the
     * response says about caching.
     *
     * Both halves are deliberate. A date the application set itself is left
     * alone, for the same reason an application's own ETag is (§7) — and it is
     * checked with has() rather than getLastModified(), because
     * HeaderBag::getDate() throws on a value it cannot parse.
     *
     * The Cache-Control dance is the surprising half. Symfony recomputes an
     * unset Cache-Control the moment a Last-Modified appears
     * (ResponseHeaderBag.php:239), turning the conservative "no-cache, private"
     * into "private, must-revalidate" to allow heuristic expiration — which
     * lets a browser reuse its copy for a fraction of the document's age
     * without revalidating at all. A package whose whole purpose is
     * revalidation must not be what stops a resource revalidating, and design
     * §10 makes cache policy a non-goal, so whatever the response said before
     * is what it says after. An application that set a policy explicitly is
     * untouched either way: its own value suppresses the recomputation.
     */
    private function attachLastModified(Response $response, Validator $validator): void
    {
        if (! $validator->lastModified instanceof DateTimeImmutable || $response->headers->has('Last-Modified')) {
            return;
        }

        $cacheControl = $response->headers->get('Cache-Control');

        $response->setLastModified($validator->lastModified);

        // Restoring with set() populates ResponseHeaderBag::$cacheControl, so a
        // value Symfony had computed becomes an explicit one and stops being
        // recomputed. A later downstream Expires or max-age therefore no longer
        // triggers the recomputation it would have on an untouched response.
        // The emitted string is byte-identical today — the restored value is
        // exactly what computeCacheControlValue() had produced — and this is a
        // known consequence rather than an accident: the alternative is leaving
        // the "private, must-revalidate" that letting the recomputation stand
        // would write, which is the one thing a revalidation package must not
        // do (see the docblock above).
        if ($cacheControl !== null && $response->headers->get('Cache-Control') !== $cacheControl) {
            $response->headers->set('Cache-Control', $cacheControl);
        }
    }

    /**
     * Finish a 304 the way the framework would have, wherever we sit.
     *
     * Response::prepare()'s empty-response branch nulls the content, strips
     * Content-Type and Content-Length, and clears PHP's `default_mimetype` ini
     * setting — the last of which is what stops the SAPI adding a Content-Type
     * of its own to a bodiless response. Under route or group placement
     * Router::prepareResponse() runs after this middleware and does it anyway;
     * under kernel-global placement nothing re-prepares, and RFC 9111 §4.3.4
     * has a cache adopt the 304's headers, so a leaked text/html would
     * overwrite the application/json a client had stored. Preparing a second
     * time changes nothing, so both placements simply call it.
     */
    private function complete(Request $request, Response $response): Response
    {
        return $response->prepare($request);
    }

    /**
     * A complete 304 for a validator known before the controller ran, or null
     * when the client's tags do not match it and the controller has to run.
     *
     * Symfony owns the comparison here exactly as it does after the controller,
     * and setNotModified() strips the response back to a compliant 304 —
     * status, empty body, forbidden headers removed, ETag kept. Laravel's
     * Router::toResponse() then normalises any 304 the same way whichever path
     * produced it, which is what makes the two indistinguishable to a client.
     *
     * Laravel's own Response rather than Symfony's: toResponse() passes any
     * SymfonyResponse straight through unwrapped, so a bare one would reach
     * outer middleware — and the test suite — missing every convenience
     * ResponseTrait adds, status() and header() among them.
     */
    private function notModified(Request $request, Validator $validator): ?Response
    {
        if ($this->wildcardOnly($request, $validator) || $this->dateOnly($request)) {
            return null;
        }

        $response = new IlluminateResponse;
        $response->setEtag($validator->etag, $validator->weak);
        $this->attachLastModified($response, $validator);

        return $response->isNotModified($request) ? $this->complete($request, $response) : null;
    }

    /**
     * Whether a date is the only thing the client has offered.
     *
     * The same rule wildcardOnly() draws, drawn on the other header.
     * Response::isNotModified() takes its date branch whenever the client sent
     * no entity tags, so `If-Modified-Since` with a far-future date short-
     * circuits to 304 for a client that holds nothing — a date needs no prior
     * access, exactly as `If-None-Match: *` does not. Behind a gate declared
     * after `conditional` the status code would hand a client that has never
     * held the representation both its existence and, by bisection on the date,
     * the second it last changed in; any rate limiter, subscription check or
     * other middleware in that position is bypassed with zero knowledge too.
     *
     * Falling through surrenders the compute saving for date-only clients and
     * nothing else. The controller and every later middleware run, attach()
     * hands the response to the same Symfony comparison, and a client that is
     * allowed through still gets its 304 — only later.
     *
     * A date sent ALONGSIDE a matching tag keeps the short-circuit: that client
     * demonstrably holds the version, which is the line the wildcard rule
     * already draws. getETags() reads If-None-Match, so a non-empty list means
     * wildcardOnly() has already had its say about what those tags were.
     */
    private function dateOnly(Request $request): bool
    {
        return $request->getETags() === [] && $request->headers->has('If-Modified-Since');
    }

    /**
     * Whether a wildcard is the only reason the client's tags would match.
     *
     * `If-None-Match: *` matches every validator there is, so answering it
     * before the controller hands a 304 to a client that holds nothing and has
     * passed nothing declared after `conditional`. With a gate in that
     * position the status code becomes an existence oracle — 304 for a record
     * that exists, 404 for one that does not, for every id, with the gate
     * never entered. Symfony is right to treat `*` as a match (RFC 9110
     * §13.1.2); what cannot be right is treating it as licence to skip the
     * rest of the pipeline.
     *
     * Falling through gives up the compute the short-circuit would have saved
     * on a wildcard read, which is a rare idiom and no saving worth an oracle,
     * and changes nothing else: the controller and every middleware after
     * `conditional` run, attach() hands the response to the same Symfony
     * comparison, and a client that is allowed through still gets its 304.
     *
     * A concrete tag alongside the wildcard is a client that does hold a
     * validator, and it keeps the short-circuit. The comparison mirrors
     * Response::isNotModified() so the two cannot disagree about what matched:
     * weakness prefixes stripped from both sides, quoted forms compared, and
     * the stripping done before the wildcard test rather than after it. That
     * order is Symfony's (Response.php:1135) and it is load-bearing, not
     * cosmetic. `W/*` is not a wildcard under §13.1.2's grammar — nothing is a
     * wildcard but a bare `*` — yet Symfony drops the prefix first and then
     * matches on the `*` left behind. Testing the raw token here would call it
     * a concrete tag, find it does not match, and grant a short-circuit that
     * isNotModified() then answers 304: the oracle back verbatim, spelled
     * differently. Whatever Symfony will call a wildcard has to be one here.
     */
    private function wildcardOnly(Request $request, Validator $validator): bool
    {
        $etag = $this->strongForm($validator->header());
        $wildcard = false;

        foreach ($request->getETags() as $candidate) {
            $candidate = $this->strongForm($candidate);

            if ($candidate === '*') {
                $wildcard = true;

                continue;
            }

            if ($candidate === $etag) {
                return false;
            }
        }

        return $wildcard;
    }

    /**
     * An entity tag with its weakness prefix dropped, which is all RFC 9110
     * §8.8.3.2's weak comparison asks for.
     */
    private function strongForm(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }

    /**
     * Empty a response body while preserving the length it advertised.
     *
     * Mirrors what Symfony's Response::prepare() does for a HEAD request.
     */
    private function withoutBody(Request $request, Response $response): Response
    {
        // setContent(null) is a no-op on a BinaryFileResponse — its body is a
        // file read at send time, suppressed by prepare() zeroing the read
        // length for a HEAD request. That length is protected with no setter, so
        // re-preparing against the restored method is how it gets zeroed: the
        // same call Router::prepareResponse() already makes, this time seeing
        // the method the client actually sent rather than our temporary GET.
        if ($response instanceof BinaryFileResponse) {
            return $response->prepare($request);
        }

        $length = $response->headers->get('Content-Length');

        $response->setContent(null);

        if ($length !== null) {
            $response->headers->set('Content-Length', $length);
        }

        return $response;
    }

    /**
     * The strategy this route asked for, or the configured default.
     */
    private function strategyName(Flags $flags): string
    {
        return $flags->strategyOr(
            (string) $this->config->get('laravel-conditional-requests.strategy'),
        );
    }

    /**
     * Whether this request should take part in the read path at all.
     *
     * Request-shaped only: no response is needed, which is what lets it run
     * before the controller and gate the pre-controller short-circuit.
     */
    private function requestEligible(Request $request): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        if (! in_array($request->getMethod(), $this->methods(), strict: true)) {
            return false;
        }

        return ! $this->excluded($request);
    }

    /**
     * Whether this response can carry a validator.
     *
     * The stream, binary, and size rules all exist for one reason — a validator
     * derived from the body means reading the whole body. They are suppressed
     * only when a validator is already in hand, because that one demonstrably
     * cost nothing whatever the response turned out to be.
     *
     * Implementing RequestValidatorStrategy is not enough on its own. A
     * strategy that answers from the request when it can and returns null when
     * it cannot reaches attach() with nothing, and fromResponse() is then asked
     * for a validator the ordinary way — so it faces the same rules every other
     * response-derived validator faces.
     */
    private function eligible(Response $response, bool $held): bool
    {
        if (! $response->isSuccessful() || $response->getEtag() !== null) {
            return false;
        }

        if ($held) {
            return true;
        }

        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return false;
        }

        $content = $response->getContent();

        if ($content === false) {
            return false;
        }

        $ceiling = (int) $this->config->get('laravel-conditional-requests.max_response_bytes');

        return $ceiling <= 0 || strlen($content) <= $ceiling;
    }

    /**
     * @return list<string>
     */
    private function methods(): array
    {
        return array_map(strtoupper(...), $this->stringList('methods'));
    }

    /**
     * Match the request against the configured route-name and URI exclusions.
     */
    private function excluded(Request $request): bool
    {
        $patterns = $this->stringList('exclude');

        if ($patterns === []) {
            return false;
        }

        return $request->routeIs(...$patterns) || $request->is(...$patterns);
    }

    /**
     * The master switch, honoured by both paths.
     */
    private function enabled(): bool
    {
        return (bool) $this->config->get('laravel-conditional-requests.enabled');
    }

    /**
     * A short identifier for the request, for a configuration error message.
     */
    private function label(Request $request): string
    {
        return $request->getMethod().' '.$request->getPathInfo();
    }

    /**
     * A translated message, or an empty string when the key resolves to
     * something that is not one — Symfony then renders the status code's own
     * reason phrase rather than an array cast into nonsense.
     */
    private function message(string $key): string
    {
        $message = $this->translator->get($key);

        return is_string($message) ? $message : '';
    }

    /**
     * Read a config key as a list of strings, discarding anything that is not one.
     *
     * A published config can hold a bare string where a list is documented; that
     * degrades to a no-op here rather than a TypeError deep in the stack.
     *
     * @return list<string>
     */
    private function stringList(string $key): array
    {
        $values = [];
        $configured = $this->config->get("laravel-conditional-requests.{$key}");

        foreach (is_iterable($configured) ? $configured : [$configured] as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
