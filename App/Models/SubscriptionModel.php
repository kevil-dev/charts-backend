<?php

namespace App\Models;

class SubscriptionModel
{
    // ─── users billing fields ──────────────────────────────────────────────

    public function getUserById(int $userId): ?object
    {
        return \QB::table('users')->where('id', $userId)->first() ?: null;
    }

    public function setStripeCustomerId(int $userId, string $customerId): void
    {
        \QB::table('users')->where('id', $userId)->update([
            'stripe_customer_id' => $customerId,
            'updated_at'         => date('Y-m-d H:i:s'),
        ]);
    }

    public function setUserPlan(int $userId, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        \QB::table('users')->where('id', $userId)->update($fields);
    }

    // ─── subscriptions table ───────────────────────────────────────────────

    public function findByStripeSubId(string $subId): ?object
    {
        return \QB::table('subscriptions')
            ->where('stripe_subscription_id', $subId)
            ->first() ?: null;
    }

    public function findActiveByUser(int $userId): ?object
    {
        return \QB::table('subscriptions')
            ->where('user_id', $userId)
            ->whereIn('status', ['trialing', 'active', 'past_due'])
            ->orderBy('created_at', 'DESC')
            ->first() ?: null;
    }

    public function create(array $data): int
    {
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        return \QB::table('subscriptions')->insert($data);
    }

    public function updateByStripeSubId(string $subId, array $fields): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        \QB::table('subscriptions')
            ->where('stripe_subscription_id', $subId)
            ->update($fields);
    }

    // ─── webhook idempotency ───────────────────────────────────────────────

    public function eventAlreadyProcessed(string $eventId): bool
    {
        return \QB::table('webhook_events')
            ->where('event_id', $eventId)
            ->count() > 0;
    }

    public function markEventProcessed(string $eventId, string $eventType): void
    {
        \QB::table('webhook_events')->insert([
            'event_id'     => $eventId,
            'event_type'   => $eventType,
            'processed_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
