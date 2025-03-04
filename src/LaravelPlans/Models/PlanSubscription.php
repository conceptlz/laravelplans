<?php

namespace Conceptlz\LaravelPlans\Models;

use DB;
use App;
use Carbon\Carbon;
use LogicException;
use Conceptlz\LaravelPlans\Period;
use Illuminate\Support\Facades\Event;
use Illuminate\Database\Eloquent\Model;
use Conceptlz\LaravelPlans\Models\PlanFeature;
use Conceptlz\LaravelPlans\SubscriptionAbility;
use Conceptlz\LaravelPlans\Traits\BelongsToPlan;
use Conceptlz\LaravelPlans\Contracts\PlanInterface;
use Conceptlz\LaravelPlans\SubscriptionUsageManager;
use Conceptlz\LaravelPlans\Events\SubscriptionCreated;
use Conceptlz\LaravelPlans\Events\SubscriptionRenewed;
use Conceptlz\LaravelPlans\Events\SubscriptionCanceled;
use Conceptlz\LaravelPlans\Events\SubscriptionPlanChanged;
use Conceptlz\LaravelPlans\Contracts\PlanSubscriptionInterface;
use Conceptlz\LaravelPlans\Exceptions\InvalidPlanFeatureException;
use Conceptlz\LaravelPlans\Exceptions\FeatureValueFormatIncompatibleException;

class PlanSubscription extends Model implements PlanSubscriptionInterface
{
    use BelongsToPlan;

    /**
     * Subscription statuses
     */
    const STATUS_ENDED      = 'ended';
    const STATUS_ACTIVE     = 'active';
    const STATUS_CANCELED   = 'canceled';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'plan_id',
        'name',
        'trial_ends_at',
        'starts_at',
        'ends_at',
        'canceled_at'
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'created_at', 'updated_at',
        'canceled_at', 'trial_ends_at', 'ends_at', 'starts_at'
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'canceled_immediately' => 'boolean',
    ];

    /**
     * Subscription Ability Manager instance.
     *
     * @var Conceptlz\LaravelPlans\SubscriptionAbility
     */
    protected $ability;

    /**
     * Boot function for using with User Events.
     *
     * @todo Move events to an Observer.
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::created(function ($model) {
            SubscriptionCreated::dispatch($model);
//            event(new SubscriptionCreated($model));
//            Event::dispatch(new SubscriptionCreated($model));
        });

        static::saving(function ($model) {
            // Set period if it wasn't set
            if (! $model->ends_at) {
                $model->setNewPeriod();
            }
        });

        static::saved(function ($model) {
            // check if there is a plan and it is changed
            if ($model->getOriginal('plan_id') && $model->getOriginal('plan_id') !== $model->plan_id) {
                event(new SubscriptionPlanChanged($model));
            }
        });
    }

    /**
     * Get the subscribable of the model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function subscribable()
    {
        return $this->morphTo();
    }

    /**
     * Get subscription usage.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function usage()
    {
        return $this->hasMany(
            config('laravelplans.models.plan_subscription_usage'),
            'subscription_id'
        );
    }

    /**
     * Get status attribute.
     *
     * @return string
     */
    public function getStatusAttribute()
    {
        if ($this->isActive()) {
            return self::STATUS_ACTIVE;
        }

        if ($this->isCanceled()) {
            return self::STATUS_CANCELED;
        }

        if ($this->isEnded()) {
            return self::STATUS_ENDED;
        }
    }

    /**
     * Check if subscription is active.
     *
     * @return bool
     */
    public function isActive()
    {
        if ((! $this->isEnded() or $this->onTrial()) and ! $this->isCanceledImmediately()) {
            return true;
        }

        return false;
    }

    /**
     * Check if subscription is trialling.
     *
     * @return bool
     */
    public function onTrial()
    {
        if (! is_null($trialEndsAt = $this->trial_ends_at)) {
            return Carbon::now()->lt(Carbon::instance($trialEndsAt));
        }

        return false;
    }

    /**
     * Check if subscription is canceled.
     *
     * @return bool
     */
    public function isCanceled()
    {
        return  ! is_null($this->canceled_at);
    }

    /**
     * Check if subscription is canceled immediately.
     *
     * @return bool
     */
    public function isCanceledImmediately()
    {
        return  (! is_null($this->canceled_at) and $this->canceled_immediately === true);
    }

    /**
     * Check if subscription period has ended.
     *
     * @return bool
     */
    public function isEnded()
    {
        $endsAt = Carbon::instance($this->ends_at);

        return Carbon::now()->gte($endsAt);
    }

    /**
     * Cancel subscription.
     *
     * @param  bool $immediately
     * @return $this
     */
    public function cancel($immediately = false)
    {
        $this->canceled_at = Carbon::now();

        if ($immediately) {
            $this->canceled_immediately = true;
        }

        if ($this->save()) {
            event(new SubscriptionCanceled($this));

            return $this;
        }

        return false;
    }

    /**
     * Change subscription plan.
     *
     * @param mixed $plan Plan Id or Plan Model Instance
     * @return $this
     */
    public function changePlan($plan)
    {
        if (is_numeric($plan)) {
            $plan = App::make(PlanInterface::class)->find($plan);
        }

        // If plans doesn't have the same billing frequency (e.g., interval
        // and interval_count) we will update the billing dates starting
        // today... and sice we are basically creating a new billing cycle,
        // the usage data will be cleared.
        if (is_null($this->plan) or $this->plan->interval !== $plan->interval or
                $this->plan->interval_count !== $plan->interval_count) {
            // Set period
            $this->setNewPeriod($plan->interval, $plan->interval_count);

            // Clear usage data
            $usageManager = new SubscriptionUsageManager($this);
            $usageManager->clear();
        }

        // Attach new plan to subscription
        $this->plan_id = $plan->id;

        return $this;
    }

    /**
     * Renew subscription period.
     *
     * @throws  \LogicException
     * @return  $this
     */
    public function renew()
    {
        if ($this->isEnded() and $this->isCanceled()) {
            throw new LogicException(
                'Unable to renew canceled ended subscription.'
            );
        }

        $subscription = $this;

        DB::transaction(function () use ($subscription) {
            // Clear usage data
            $usageManager = new SubscriptionUsageManager($subscription);
            $usageManager->clear();

            // Renew period
            $subscription->setNewPeriod();
            $subscription->canceled_at = null;
            $subscription->save();
        });

        event(new SubscriptionRenewed($this));

        return $this;
    }

    /**
     * Get Subscription Ability instance.
     *
     * @return \Conceptlz\LaravelPlans\SubscriptionAbility
     */
    public function ability()
    {
        if (is_null($this->ability)) {
            return new SubscriptionAbility($this);
        }

        return $this->ability;
    }

    /**
     * Find by subscribable id.
     *
     * @param  \Illuminate\Database\Eloquent\Builder
     * @param  int $subscribable
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByUser($query, $subscribable)
    {
        return $query->where('subscribable_id', $subscribable);
    }

    /**
     * Find subscription with an ending trial.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFindEndingTrial($query, $dayRange = 3)
    {
        $from = Carbon::now();
        $to = Carbon::now()->addDays($dayRange);

        $query->whereBetween('trial_ends_at', [$from, $to]);
    }

    /**
     * Find subscription with an ended trial.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFindEndedTrial($query)
    {
        $query->where('trial_ends_at', '<=', date('Y-m-d H:i:s'));
    }

    /**
     * Find ending subscriptions.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFindEndingPeriod($query, $dayRange = 3)
    {
        $from = Carbon::now();
        $to = Carbon::now()->addDays($dayRange);

        $query->whereBetween('ends_at', [$from, $to]);
    }

    /**
     * Find ended subscriptions.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeFindEndedPeriod($query)
    {
        $query->where('ends_at', '<=', date('Y-m-d H:i:s'));
    }

    /**
     * Set subscription period.
     *
     * @param  string $interval
     * @param  int $interval_count
     * @param  string $start Start date
     * @return  $this
     */
    protected function setNewPeriod($interval = '', $interval_count = '', $start = '')
    {
        if (empty($interval)) {
            $interval = $this->plan->interval;
        }

        if (empty($interval_count)) {
            $interval_count = $this->plan->interval_count;
        }

        $period = new Period($interval, $interval_count, $start);

        $this->starts_at = $period->getStartDate();
        $this->ends_at = $period->getEndDate();

        return $this;
    }
}
