<?php

namespace App\Controllers;

use App\Enums\ResponseStatusEnum;
use App\Models\SubscriptionModel;
use Razorpay\Api\Api;

class BillingController extends Controller
{
    private SubscriptionModel $model;
    private Api $rzp;

    public function __construct($vars = [])
    {
        parent::__construct($vars);

        // webhook is called by Razorpay (no user cookie) — exclude from auth
        $this->middleware([
            $this->auth_user_key => [
                'class'  => __CLASS__,
                'except' => ['webhook'],
            ],
        ]);

        if (isset($this->mw[$this->auth_user_key])) {
            $this->auto_id  = $this->mw[$this->auth_user_key]->auto_id  ?? null;
            $this->email_id = $this->mw[$this->auth_user_key]->email_id ?? null;
        }

        $this->model = new SubscriptionModel();
        $this->rzp   = new Api(RAZORPAY_KEY_ID, RAZORPAY_KEY_SECRET);
    }

    // ─── map tier+interval → configured plan id ────────────────────────────

    private function resolvePlanId(string $tier, string $interval): ?string
    {
        $map = [
            'tier1_monthly' => RAZORPAY_PLAN_TIER1_MONTHLY,
            'tier1_yearly'  => RAZORPAY_PLAN_TIER1_YEARLY,
            'tier2_monthly' => RAZORPAY_PLAN_TIER2_MONTHLY,
            'tier2_yearly'  => RAZORPAY_PLAN_TIER2_YEARLY,
        ];
        return $map["{$tier}_{$interval}"] ?? null;
    }

    // ─── POST /billing/checkout ────────────────────────────────────────────
    // Creates a Razorpay subscription with a 14-day trial (start_at = +14d).
    // Returns subscription_id + key_id for the frontend checkout modal.

    public function checkout(): void
    {
        $this->validateInput([
            'tier'     => 'required|in:tier1,tier2',
            'interval' => 'required|in:monthly,yearly',
        ]);

        $tier     = $this->payload['tier'];
        $interval = $this->payload['interval'];

        $planId = $this->resolvePlanId($tier, $interval);
        if (!$planId) {
            $this->sendJson(ResponseStatusEnum::INVALID_PLAN);
        }

        // Block double-subscribing if an active/trialing sub already exists
        $existing = $this->model->findActiveByUser($this->auto_id);
        if ($existing) {
            $this->sendJson(ResponseStatusEnum::ALREADY_PAID);
        }

        // total_count: yearly billed once/year, monthly once/month.
        // High enough to act as "until cancelled" — 10 years.
        $totalCount = $interval === 'yearly' ? 10 : 120;

        // 14-day trial: first real charge delayed to now + 14 days.
        $startAt = time() + (14 * 24 * 60 * 60);

        try {
            $sub = $this->rzp->subscription->create([
                'plan_id'        => $planId,
                'total_count'    => $totalCount,
                'quantity'       => 1,
                'start_at'       => $startAt,
                'customer_notify'=> 1,
                'notes'          => [
                    'user_id' => (string) $this->auto_id,
                    'tier'    => $tier,
                ],
            ]);
        } catch (\Exception $e) {
            $this->sendJson(ResponseStatusEnum::UNABLE_TO_PROCESS, $e->getMessage());
        }

        // Persist immediately in 'created' state. Webhook will move it forward.
        $this->model->create([
            'user_id'                  => $this->auto_id,
            'razorpay_subscription_id' => $sub['id'],
            'razorpay_plan_id'         => $planId,
            'tier'                     => $tier,
            'status'                   => 'created',
            'current_period_end'       => date('Y-m-d H:i:s', $startAt),
            'cancel_at_period_end'     => 0,
        ]);

        $this->model->setUserPlan($this->auto_id, [
            'plan_status'   => 'trialing',
            'selected_tier' => $tier,
            'trial_ends_at' => date('Y-m-d H:i:s', $startAt),
        ]);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "", [
            'subscription_id' => $sub['id'],
            'key_id'          => RAZORPAY_KEY_ID,
        ]);
    }

    // ─── POST /billing/webhook ─────────────────────────────────────────────
    // Razorpay → us. Verify signature, dedupe, then sync state.

    public function webhook(): void
    {
        $rawBody   = file_get_contents('php://input');
        $signature = $this->input->request_headers()['X-Razorpay-Signature'] ?? '';

        // 1. Signature verification — reject forgeries.
        try {
            $this->rzp->utility->verifyWebhookSignature(
                $rawBody,
                $signature,
                RAZORPAY_WEBHOOK_SECRET
            );
        } catch (\Exception $e) {
            http_response_code(400);
            echo 'invalid signature';
            exit;
        }

        $payload = json_decode($rawBody, true);
        $event   = $payload['event'] ?? '';

        // 2. Idempotency — Razorpay may deliver the same event more than once.
        $eventId = $this->input->request_headers()['X-Razorpay-Event-Id'] ?? '';
        if ($eventId && $this->model->eventAlreadyProcessed($eventId)) {
            http_response_code(200);
            echo 'ok';
            exit;
        }

        // 3. Extract subscription entity (present on all subscription.* events)
        $sub = $payload['payload']['subscription']['entity'] ?? null;

        if ($sub) {
            $subId  = $sub['id'];
            $userId = isset($sub['notes']['user_id']) ? (int) $sub['notes']['user_id'] : null;

            switch ($event) {
                case 'subscription.activated':
                    // Trial ended, first charge succeeded → fully paid
                    $this->model->updateByRazorpaySubId($subId, [
                        'status'             => 'active',
                        'current_period_end' => date('Y-m-d H:i:s', $sub['current_end'] ?? time()),
                    ]);
                    if ($userId) {
                        $this->model->setUserPlan($userId, ['plan_status' => 'active']);
                    }
                    break;

                case 'subscription.charged':
                    // A recurring charge succeeded → roll the period forward
                    $this->model->updateByRazorpaySubId($subId, [
                        'status'             => 'active',
                        'current_period_end' => date('Y-m-d H:i:s', $sub['current_end'] ?? time()),
                    ]);
                    if ($userId) {
                        $this->model->setUserPlan($userId, ['plan_status' => 'active']);
                    }
                    break;

                case 'subscription.cancelled':
                    $this->model->updateByRazorpaySubId($subId, ['status' => 'cancelled']);
                    if ($userId) {
                        // Recoverable deactivation: keep tier/customer id, flip access off
                        $this->model->setUserPlan($userId, ['plan_status' => 'deactivated']);
                    }
                    break;

                case 'subscription.completed':
                    $this->model->updateByRazorpaySubId($subId, ['status' => 'completed']);
                    if ($userId) {
                        $this->model->setUserPlan($userId, ['plan_status' => 'deactivated']);
                    }
                    break;
            }
        }

        // 4. Record the event so a retry is a no-op
        if ($eventId) {
            $this->model->markEventProcessed($eventId, $event);
        }

        http_response_code(200);
        echo 'ok';
        exit;
    }

    // ─── POST /billing/cancel ──────────────────────────────────────────────
    // Cancel at period end (no refund, access until current_period_end).
    // Local plan_status is NOT flipped here — the cancelled webhook does that.

    public function cancel(): void
    {
        $sub = $this->model->findActiveByUser($this->auto_id);
        if (!$sub) {
            $this->sendJson(ResponseStatusEnum::NO_SUBSCRIPTION);
        }

        try {
            $this->rzp->subscription
                ->fetch($sub->razorpay_subscription_id)
                ->cancel(['cancel_at_cycle_end' => 1]);
        } catch (\Exception $e) {
            $this->sendJson(ResponseStatusEnum::UNABLE_TO_PROCESS, $e->getMessage());
        }

        $this->model->updateByRazorpaySubId($sub->razorpay_subscription_id, [
            'cancel_at_period_end' => 1,
        ]);

        $this->sendJson(ResponseStatusEnum::SUCCESS, "Subscription will end at the end of the current period.");
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
            'plan_status'          => $user->plan_status,
            'selected_tier'        => $user->selected_tier,
            'trial_ends_at'        => $user->trial_ends_at,
            'current_period_end'   => $sub->current_period_end ?? null,
            'cancel_at_period_end' => $sub ? (bool) $sub->cancel_at_period_end : false,
        ]);
    }
}