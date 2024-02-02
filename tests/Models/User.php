<?php

namespace Conceptlz\LaravelPlans\Tests\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Conceptlz\LaravelPlans\Contracts\PlanSubscriberInterface;
use Conceptlz\LaravelPlans\Traits\PlanSubscriber;

class User extends Authenticatable implements PlanSubscriberInterface
{
    use PlanSubscriber;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name', 'email', 'password',
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token',
    ];
}
