<?php

declare(strict_types=1);

namespace ExpertSystems\ConditionalRequests\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * A second fixture in a different table, used to prove two records with the
 * same key and the same timestamp cannot share a validator.
 *
 * @property int $id
 * @property string $body
 */
class Note extends Model
{
    protected $guarded = [];
}
