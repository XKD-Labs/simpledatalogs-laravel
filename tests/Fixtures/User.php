<?php

namespace SimpleDataLogs\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as AuthenticatableUser;

/**
 * A plain user for the auth listener tests.
 */
class User extends AuthenticatableUser
{
    /**
     * Every column is fillable in tests.
     *
     * @var array<int, string>
     */
    protected $guarded = [];
}
