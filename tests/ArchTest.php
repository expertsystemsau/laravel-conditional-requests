<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

arch('it will not use dd(), ddd(), env(), or exit()')
    ->expect(['dd', 'ddd', 'env', 'exit'])
    ->each->not->toBeUsed();

arch('the package source declares strict types')
    ->expect('ExpertSystems\ConditionalRequests')
    ->toUseStrictTypes();

arch('every class in the package is final')
    ->expect('ExpertSystems\ConditionalRequests')
    ->classes()
    ->toBeFinal()
    ->ignoring('ExpertSystems\ConditionalRequests\Tests');

arch('every contract is an interface')
    ->expect('ExpertSystems\ConditionalRequests\Contracts')
    ->toBeInterfaces();
