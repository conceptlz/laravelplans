<?php

namespace Conceptlz\LaravelPlans\Events;

use Conceptlz\LaravelPlans\Models\PlanSubscription;
use Illuminate\Queue\SerializesModels;

class SubscriptionPlanChanged
{
    use SerializesModels;

    /**
     * @var \Conceptlz\LaravelPlans\Models\PlanSubscription
     */
    public $subscription;

    /**
     * Create a new event instance.
     *
     * @param  \Conceptlz\LaravelPlans\Models\PlanSubscription  $subscription
     * @return void
     */
    public function __construct(PlanSubscription $subscription)
    {
        $this->subscription = $subscription;
    }
}
