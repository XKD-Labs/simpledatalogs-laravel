<?php

namespace SimpleDataLogs\Laravel\Tests\Fixtures;

use SimpleDataLogs\Laravel\TracksModelEvents;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A soft-deleting model that opts into tracking and whitelists one attribute value.
 */
class Order extends Model
{
    use SoftDeletes;
    use TracksModelEvents;

    /**
     * Every column is fillable in tests.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Never shown, so never sent even if whitelisted.
     *
     * @var array<int, string>
     */
    protected $hidden = ["card_token"];

    /**
     * The attributes whose values may be sent.
     *
     * @var array<int, string>
     */
    protected array $trackedAttributeValues = ["status", "card_token"];
}
