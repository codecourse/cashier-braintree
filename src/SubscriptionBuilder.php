<?php

namespace Laravel\Cashier;

use Exception;
use Carbon\Carbon;
use Braintree\Customer;
use Braintree\Subscription as BraintreeSubscription;

class SubscriptionBuilder
{
    /**
     * The model that is subscribing.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $owner;

    /**
     * The name of the subscription.
     *
     * @var string
     */
    protected $name;

    /**
     * The name of the plan being subscribed to.
     *
     * @var string
     */
    protected $plan;

    /**
     * The number of trial days to apply to the subscription.
     *
     * @var int|null
     */
    protected $trialDays;

    /**
     * Indicates that the trial should end immediately.
     *
     * @var bool
     */
    protected $skipTrial = false;

    /**
     * The coupon code being applied to the customer.
     *
     * @var string|null
     */
    protected $coupon;

    /**
     * Create a new subscription builder instance.
     *
     * @param  mixed  $owner
     * @param  string  $name
     * @param  string  $plan
     * @return void
     */
    public function __construct($owner, $name, $plan)
    {
        $this->name = $name;
        $this->plan = $plan;
        $this->owner = $owner;
    }

    /**
     * Specify the ending date of the trial.
     *
     * @param  int  $trialDays
     * @return $this
     */
    public function trialDays($trialDays)
    {
        $this->trialDays = $trialDays;

        return $this;
    }

    /**
     * Force the trial to end immediately.
     *
     * @return $this
     */
    public function skipTrial()
    {
        $this->skipTrial = true;

        return $this;
    }

    /**
     * The coupon to apply to a new subscription.
     *
     * @param  string  $coupon
     * @return $this
     */
    public function withCoupon($coupon)
    {
        $this->coupon = $coupon;

        return $this;
    }

    /**
     * Add a new Braintree subscription to the model.
     *
     * @param  array  $options
     * @return \Laravel\Cashier\Subscription
     * @throws \Exception
     */
    public function add(array $options = [])
    {
        return $this->create(null, $options);
    }

    /**
     * Create a new Braintree subscription.
     *
     * @param  string|null  $token
     * @param  array  $customerOptions
     * @param  array  $subscriptionOptions
     * @return \Laravel\Cashier\Subscription
     * @throws \Exception
     */
    public function create($token = null, array $customerOptions = [], array $subscriptionOptions = []): Subscription
    {
        $payload = $this->getSubscriptionPayload(
            $this->getBraintreeCustomer($token, $customerOptions), $subscriptionOptions
        );

        if ($this->coupon) {
            $payload = $this->addCouponToPayload($payload);
        }

        $response = BraintreeSubscription::create($payload);

        if (! $response->success) {
            throw new Exception('Braintree failed to create subscription: '.$response->message);
        }

        if ($this->skipTrial) {
            $trialEndsAt = null;
        } else {
            $trialEndsAt = $this->trialDays ? Carbon::now()->addDays($this->trialDays) : null;
        }

        return $this->owner->subscriptions()->create([
            'name' => $this->name,
            'braintree_id'   => $response->subscription->id,
            'braintree_plan' => $this->plan,
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
            'ends_at' => null,
        ]);
    }

    /**
     * Get the base subscription payload for Braintree.
     *
     * @param  \Braintree\Customer  $customer
     * @param  array  $options
     * @return array
     * @throws \Exception
     */
    protected function getSubscriptionPayload($customer, array $options = [])
    {
        $plan = BraintreeService::findPlan($this->plan);

        if ($this->skipTrial) {
            $trialDuration = 0;
        } else {
            $trialDuration = $this->trialDays ?: 0;
        }

        return array_merge([
            'planId' => $this->plan,
            'price' => number_format($plan->price * (1 + ($this->owner->taxPercentage() / 100)), 2, '.', ''),
            'paymentMethodToken' => $this->owner->paymentMethod()->token,
            'trialPeriod' => $this->trialDays && ! $this->skipTrial ? true : false,
            'trialDurationUnit' => 'day',
            'trialDuration' => $trialDuration,
        ], $options);
    }

    /**
     * Add the coupon discount to the Braintree payload.
     *
     * @param  array  $payload
     * @return array
     */
    protected function addCouponToPayload(array $payload)
    {
        if (! isset($payload['discounts']['add'])) {
            $payload['discounts']['add'] = [];
        }

        $payload['discounts']['add'][] = [
            'inheritedFromId' => $this->coupon,
        ];

        return $payload;
    }

    /**
     * Get the Braintree customer instance for the current user and token.
     *
     * @param  string|null  $token
     * @param  array  $options
     * @return \Braintree\Customer
     */
    protected function getBraintreeCustomer($token = null, array $options = []): Customer
    {
        if (! $this->owner->braintree_id) {
            $customer = $this->owner->createAsBraintreeCustomer($token, $options);
        } else {
            $customer = $this->owner->asBraintreeCustomer();

            if ($token) {
                $this->owner->updateCard($token);
            }
        }

        return $customer;
    }
}
