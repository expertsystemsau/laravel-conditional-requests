<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A route-bindable fixture carrying both an explicit version column and the
 * usual timestamps, so one model exercises both version sources.
 *
 * @property int $id
 * @property string $title
 * @property int|null $version
 */
class Article extends Model
{
    protected $guarded = [];
}
