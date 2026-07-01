<?php

namespace App\Controllers;

use App\Enums\ResponseStatusEnum;
use App\Models\SubscriptionModel;

class BillingController extends Controller
{
    private SubscriptionModel $model;

    public function __construct($vars = [])
    {
        parent::__construct($vars);

        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);

        // webhook is called by Stripe (no user cookie) — exclude from auth
        $this->middleware([
            $this->auth_user_key => [
                'class' => __CLASS__,
                'except' => ['webhook'],
            ],
        ]);

        if (isset($this->mw[$this->auth_user_key])) {
            $this->auto_id = $this->mw[$this->auth_user_key]->auto_id ?? null;
            $this->email_id = $this->mw[$this->auth_user_key]->email_id ?? null;
        }

        $this->model = new SubscriptionModel();
    }

    // ─── map tier+interval → configured price id ───────────────────────────

    private function resolvePriceId(string $tier, string $interval): ?string
    {
        $map = [
            'pro_monthly' => STRIPE_PRICE_PRO_MONTHLY,
            'pro_yearly' => STRIPE_PRICE_PRO_YEARLY,
            'elite_monthly' => STRIPE_PRICE_ELITE_MONTHLY,
            'elite_yearly' => STRIPE_PRICE_ELITE_YEARLY,
        ];
        return $map["{$tier}_{$interval}"] ?? null;
    }

    // ─── POST /billing/checkout ────────────────────────────────────────────
    // Creates a Stripe Checkout Session with a 14-day trial (hosted redirect).
    // Returns the session URL — no modal, no subscription created yet.
    // The subscription itself is created by Stripe once checkout completes,
    // and persisted locally by the checkout.session.completed webhook.

    public function checkout(): void
    {
        $this->validateInput([
            'tier' => 'required|in:pro,elite',
            'interval' => 'required|in:monthly,yearly',
        ]);

        $tier = $this->payload['tier'];
        $interval = $this->payload['interval'];

        $priceId = $this->resolvePriceId($tier, $interval);
        if (!$priceId) {
            $this->sendJson(ResponseStatusEnum::INVALID_PLAN);
        }

        // Block double-subscribing if an active/trialing sub already exists
        $existing = $this->model->findActiveByUser($this->auto_id);
        if ($existing) {
            $this->sendJson(ResponseStatusEnum::ALREADY_PAID);
        }

        $user = $this->model->getUserById($this->auto_id);
        if (!$user) {
            $this->sendJson(ResponseStatusEnum::NO_USER);
        }

        try {
            $customerId = $user->stripe_customer_id ?? null;
            if (!$customerId) {
                $customer = \Stripe\Customer::create([
                    'email' => $user->email,
                    'metadata' => ['user_id' => (string) $this->auto_id],
                ]);
                $customerId = $customer->id;
                $this->model->setStripeCustomerId($this->auto_id, $customerId);
            }

            $session = \Stripe\Checkout\Session::create([
                'mode' => 'subscription',
                'customer' => $customerId,
                'line_items' => [
                    [
                        'price' => $priceId,
                        'quantity' => 1,
                    ]
                ],
                'subscription_data' => [
                    'trial_period_days' => 14,
                    'metadata' => [
                        'user_id' => (string) $this->auto_id,
                        'tier' => $tier,
                    ],
                ],
                'success_url' => STRIPE_SUCCESS_URL,
                'cancel_url' => STRIPE_CANCEL_URL,
            ]);
        } catch (\Exception $e) {
            $this->sendJson(ResponseStatusEnum::UNABLE_TO_PROCESS, $e->getMessage());
        }

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", [
            'url' => $session->url,
        ]);
    }

    // ─── POST /billing/webhook ─────────────────────────────────────────────
    // Stripe → us. Verify signature, then INSERT-as-lock for idempotency,
    // then sync local state.

    public function webhook(): void
    {
        $rawBody = file_get_contents('php://input');
        $sigHeader = $this->input->request_headers()['Stripe-Signature'] ?? '';

        // 1. Signature verification — reject forgeries.
        try {
            $event = \Stripe\Webhook::constructEvent($rawBody, $sigHeader, STRIPE_WEBHOOK_SECRET);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            http_response_code(400);
            echo 'invalid signature';
            exit;
        }

        // 2. Idempotency — the INSERT is the atomic lock, not a prior SELECT.
        try {
            $this->model->markEventProcessed($event->id, $event->type);
        } catch (\PDOException $e) {
            $isDuplicate = $e->getCode() === '23000' || ($e->errorInfo[1] ?? null) === 1062;
            if (!$isDuplicate) {
                throw $e;
            }
            http_response_code(200);
            echo 'ok';
            exit;
        }

        // 3. Sync local state.
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event->data->object);
                break;

            case 'customer.subscription.created':
                $this->handleSubscriptionCreated($event->data->object);
                break;

            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event->data->object);
                break;

            case 'customer.subscription.deleted':
                $this->handleSubscriptionDeleted($event->data->object);
                break;

            case 'invoice.payment_failed':
                $this->handlePaymentFailed($event->data->object);
                break;
        }

        http_response_code(200);
        echo 'ok';
        exit;
    }

    private function handleCheckoutCompleted(object $session): void
    {
        $userId = (int) $session->metadata->user_id;
        if (!$userId) {
            return;
        }

        // Always sync the customer ID.
        $this->model->setUserPlan($userId, [
            'stripe_customer_id' => $session->customer,
        ]);

        // Eagerly sync plan_status so /billing/status reflects reality
        // before the separate customer.subscription.created event arrives.
        // If that event follows (it always does), setUserPlan is idempotent —
        // writing the same values twice causes no harm.
        if (!empty($session->subscription)) {
            try {
                $stripeSub = \Stripe\Subscription::retrieve($session->subscription);
                $tier      = $stripeSub->metadata->tier ?? null;
                $trialEnd  = $stripeSub->trial_end
                    ? date('Y-m-d H:i:s', $stripeSub->trial_end)
                    : null;

                $this->model->setUserPlan($userId, [
                    'plan_status'   => $stripeSub->status,
                    'selected_tier' => $tier,
                    'trial_ends_at' => $trialEnd,
                ]);
            } catch (\Exception $e) {
                // Non-fatal — customer.subscription.created will follow
                // and sync the plan fields regardless.
            }
        }
    }

    private function handleSubscriptionCreated(\Stripe\Subscription $stripeSub): void
    {
        $userId = isset($stripeSub->metadata->user_id)
            ? (int) $stripeSub->metadata->user_id
            : null;
        $tier   = $stripeSub->metadata->tier ?? null;

        if (!$userId || !$tier) {
            return;
        }

        $priceId          = $stripeSub->items->data[0]->price->id;
        $status           = $stripeSub->status;
        // For trialing subscriptions, current_period_end = trial_end (Stripe docs confirm this).
        // Stripe CLI test events send current_period_end = now instead — so we prefer
        // trial_end when it exists to get the correct date in both test and production.
        $effectivePeriodEnd = $stripeSub->trial_end ?? $stripeSub->current_period_end;
        $currentPeriodEnd = date('Y-m-d H:i:s', $effectivePeriodEnd);
        $trialEnd         = $stripeSub->trial_end
            ? date('Y-m-d H:i:s', $stripeSub->trial_end)
            : null;

        $this->model->create([
            'user_id'                => $userId,
            'stripe_subscription_id' => $stripeSub->id,
            'stripe_customer_id'     => $stripeSub->customer,
            'stripe_price_id'        => $priceId,
            'tier'                   => $tier,
            'status'                 => $status,
            'current_period_end'     => $currentPeriodEnd,
            'cancel_at_period_end'   => 0,
        ]);

        $this->model->setUserPlan($userId, [
            'plan_status'   => $status,
            'selected_tier' => $tier,
            'trial_ends_at' => $trialEnd,
        ]);
    }

    private function handleSubscriptionUpdated(object $stripeSub): void
    {
        $local = $this->model->findByStripeSubId($stripeSub->id);
        if (!$local) {
            return;
        }

        $newStatus = $stripeSub->status;
        $effectivePeriodEnd = $stripeSub->trial_end ?? $stripeSub->current_period_end;
        $currentPeriodEnd = date('Y-m-d H:i:s', $effectivePeriodEnd);
        $cancelAtPeriodEnd = (int) $stripeSub->cancel_at_period_end;

        $this->model->updateByStripeSubId($stripeSub->id, [
            'status' => $newStatus,
            'current_period_end' => $currentPeriodEnd,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);

        $this->model->setUserPlan((int) $local->user_id, [
            'plan_status' => $newStatus,
            'trial_ends_at' => $stripeSub->trial_end
                ? date('Y-m-d H:i:s', $stripeSub->trial_end)
                : null,
        ]);
    }

    private function handleSubscriptionDeleted(object $stripeSub): void
    {
        $local = $this->model->findByStripeSubId($stripeSub->id);
        if (!$local) {
            return;
        }

        $this->model->updateByStripeSubId($stripeSub->id, [
            'status' => 'canceled',
        ]);

        $this->model->setUserPlan((int) $local->user_id, [
            'plan_status' => 'canceled',
            'selected_tier' => null,
            'trial_ends_at' => null,
        ]);
    }

    private function handlePaymentFailed(object $invoice): void
    {
        $stripeSubId = $invoice->subscription;
        if (!$stripeSubId) {
            return;
        }

        $local = $this->model->findByStripeSubId($stripeSubId);
        if (!$local) {
            return;
        }

        $this->model->updateByStripeSubId($stripeSubId, [
            'status' => 'past_due',
        ]);

        $this->model->setUserPlan((int) $local->user_id, [
            'plan_status' => 'past_due',
        ]);
    }

    // ─── POST /billing/cancel ──────────────────────────────────────────────
    // Cancel at period end (no refund, access until current_period_end).
    // Local plan_status is NOT flipped here — the webhook does that.

    public function cancel(): void
    {
        $sub = $this->model->findActiveByUser($this->auto_id);
        if (!$sub) {
            $this->sendJson(ResponseStatusEnum::NO_SUBSCRIPTION);
        }

        try {
            \Stripe\Subscription::update($sub->stripe_subscription_id, [
                'cancel_at_period_end' => true,
            ]);
        } catch (\Exception $e) {
            $this->sendJson(ResponseStatusEnum::UNABLE_TO_PROCESS, $e->getMessage());
        }

        $this->model->updateByStripeSubId($sub->stripe_subscription_id, [
            'cancel_at_period_end' => 1,
        ]);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "Subscription will end at the end of the current period.");
    }

    // ─── POST /billing/upgrade ─────────────────────────────────────────────
    // Upgrade an active Pro subscription to Elite (prorated, same interval).

    public function upgrade(): void
    {
        $sub = $this->model->findActiveByUser($this->auto_id);
        if (!$sub) {
            $this->sendJson(ResponseStatusEnum::NO_SUBSCRIPTION);
        }
        if ($sub->tier !== 'pro') {
            $this->sendJson(ResponseStatusEnum::BAD_REQUEST, 'Only Pro subscriptions can be upgraded to Elite.');
        }

        $interval = $sub->stripe_price_id === STRIPE_PRICE_PRO_YEARLY ? 'yearly' : 'monthly';
        $newPriceId = $interval === 'yearly' ? STRIPE_PRICE_ELITE_YEARLY : STRIPE_PRICE_ELITE_MONTHLY;

        try {
            $stripeSub = \Stripe\Subscription::retrieve($sub->stripe_subscription_id);
            $itemId = $stripeSub->items->data[0]->id;

            \Stripe\Subscription::update($sub->stripe_subscription_id, [
                'items' => [['id' => $itemId, 'price' => $newPriceId]],
                'proration_behavior' => 'create_prorations',
            ]);
        } catch (\Exception $e) {
            $this->sendJson(ResponseStatusEnum::UNABLE_TO_PROCESS, $e->getMessage());
        }

        $this->model->updateByStripeSubId($sub->stripe_subscription_id, [
            'tier' => 'elite',
            'stripe_price_id' => $newPriceId,
        ]);

        $this->model->setUserPlan($this->auto_id, ['selected_tier' => 'elite']);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "Upgraded to Elite.");
    }

    // ─── GET /billing/status ───────────────────────────────────────────────

    public function status(): void
    {
        $user = $this->model->getUserById($this->auto_id);
        if (!$user) {
            $this->sendJson(ResponseStatusEnum::NO_USER);
        }

        $sub = $this->model->findActiveByUser($this->auto_id);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", [
            'plan_status' => $user->plan_status,
            'selected_tier' => $user->selected_tier,
            'trial_ends_at' => $user->trial_ends_at,
            'current_period_end' => $sub->current_period_end ?? null,
            'cancel_at_period_end' => $sub ? (bool) $sub->cancel_at_period_end : false,
        ]);
    }
}
