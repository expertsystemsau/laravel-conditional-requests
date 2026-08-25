<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Contracts;

use ExpertSystems\ConditionalRequests\Validators\Validator;
use Illuminate\Http\Request;

interface ProvidesConditionalValidator
{
    /**
     * The validator for this record's current version, or null when it has none.
     *
     * The request is passed so an implementation can fold representation-affecting
     * input — content negotiation, sparse fieldsets, includes — into the tag. A
     * strong validator asserts one specific representation, so a record served in
     * more than one shape has to vary its tag with the shape.
     */
    public function conditionalValidator(Request $request): ?Validator;
}
