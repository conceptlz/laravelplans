<?php

use Conceptlz\LaravelPlans\Models\Plan;
use Conceptlz\LaravelPlans\Tests\Models\User;
use Conceptlz\LaravelPlans\Models\PlanSubscription;

$factory->define(PlanSubscription::class, function (Faker\Generator $faker) {
    return [
        'subscribable_id' => factory(User::class)->create()->id,
        'subscribable_type' => User::class,
        'plan_id' => factory(Plan::class)->create()->id,
        'name' => $faker->word
    ];
});
