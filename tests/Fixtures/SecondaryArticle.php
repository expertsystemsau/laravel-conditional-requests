<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

/**
 * The same table on a connection that is not the default one, so a test can
 * tell which connection the lock's transaction was actually opened on.
 *
 * @property int $id
 * @property string $title
 * @property int|null $version
 */
final class SecondaryArticle extends Article
{
    protected $connection = 'secondary';

    protected $table = 'articles';
}
