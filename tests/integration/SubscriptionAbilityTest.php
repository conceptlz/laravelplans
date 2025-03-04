<?php

namespace Conceptlz\LaravelPlans\Tests\Integration\Models;

use Conceptlz\LaravelPlans\Models\Plan;
use Conceptlz\LaravelPlans\Tests\TestCase;
use Conceptlz\LaravelPlans\Tests\Models\User;
use Conceptlz\LaravelPlans\Models\PlanFeature;

class SubscriptionAbilityTest extends TestCase
{
    /**
     * Can check subscription feature usage.
     *
     * @test
     * @return void
     */
    public function it_can_check_feature_usage()
    {
        $user = User::create([
            'email' => 'jack@example.dev',
            'name' => 'Jack',
            'password' => 'password'
        ]);

        $plan = Plan::create([
            'name' => 'Pro',
            'description' => 'Pro plan',
            'price' => 9.99,
            'interval' => 'month',
            'interval_count' => 1,
            'trial_period_days' => 15,
        ]);

        $plan->features()->saveMany([
            new PlanFeature(['code' => 'listings', 'value' => 50]),
            new PlanFeature(['code' => 'pictures_per_listing', 'value' => 10]),
            new PlanFeature(['code' => 'listing_title_bold', 'value' => 'N']),
            new PlanFeature(['code' => 'listing_video', 'value' => 'Y']),
            new PlanFeature(['code' => 'non_positive_word', 'value' => 'I']), // test for non-positive words
        ]);

        // Create Subscription
        $user->newSubscription('main', $plan)->create();

        $this->assertTrue($user->subscription('main')->ability()->canUse('listings'));
        $this->assertEquals(50, $user->subscription('main')->ability()->remainings('listings'));
        $this->assertEquals(0, $user->subscription('main')->ability()->consumed('listings'));
        $this->assertEquals(10, $user->subscription('main')->ability()->value('pictures_per_listing'));
        $this->assertEquals('N', $user->subscription('main')->ability()->value('listing_title_bold'));
        $this->assertFalse($user->subscription('main')->ability()->enabled('listing_title_bold'));
        $this->assertTrue($user->subscription('main')->ability()->enabled('listing_video'));
        $this->assertFalse($user->subscription('main')->ability()->canUse('non_positive_word'));
    }
}
