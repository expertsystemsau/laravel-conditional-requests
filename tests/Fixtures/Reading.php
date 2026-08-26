<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use ExpertSystems\ConditionalRequests\Concerns\HasConditionalValidator;
use ExpertSystems\ConditionalRequests\Contracts\ProvidesConditionalValidator;
use Illuminate\Database\Eloquent\Model;

/**
 * A fixture whose timestamps keep sub-second precision, as an application with
 * a datetime(6) column configures them.
 *
 * Eloquent formats a date with the model's $dateFormat the moment it is set,
 * so Article and Note — both on the default 'Y-m-d H:i:s' — cannot hold a
 * fractional second at all. This model is how the one-second granularity rule
 * gets tested against the values that actually break it.
 *
 * @property int $id
 * @property string $label
 */
class Reading extends Model implements ProvidesConditionalValidator
{
    use HasConditionalValidator;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.u';
}
