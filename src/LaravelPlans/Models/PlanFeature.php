<?php

namespace Conceptlz\LaravelPlans\Models;

use Illuminate\Database\Eloquent\Model;
use Conceptlz\LaravelPlans\Traits\BelongsToPlan;
use Conceptlz\LaravelPlans\Contracts\PlanFeatureInterface;

class PlanFeature extends Model implements PlanFeatureInterface
{
    use BelongsToPlan;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'plan_id',
        'code',
        'value',
        'sort_order'
    ];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'created_at', 'updated_at'
    ];

    /**
     * Get feature usage.
     *
     * This will return all related
     * subscriptions usages.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function usage()
    {
        return $this->hasMany(config('laravelplans.models.plan_subscription_usage'));
    }
}
