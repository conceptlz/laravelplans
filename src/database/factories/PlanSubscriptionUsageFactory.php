<?php

use Conceptlz\LaravelPlans\Models\PlanSubscription;
use Conceptlz\LaravelPlans\Models\PlanSubscriptionUsage;

$factory->define(PlanSubscriptionUsage::class, function (Faker\Generator $faker) {
    return [
        'name' => $faker->word,
        'subscription_id' => factory(PlanSubscription::class)->create()->id,
        'code' => $faker->word,
        'used' => rand(1, 50),
        'valid_until' => $faker->dateTime()
    ];
});
