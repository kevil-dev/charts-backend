<?php

namespace App\Traits;

use App\Enums\EntitlementEnum;

trait EntitlementTrait
{
    public function resolveTier(?object $user): string
    {
        if ($user === null) {
            return 'guest';
        }

        $planStatus = $user->plan_status ?? null;
        $tier       = $user->selected_tier ?? null;

        if (in_array($planStatus, ['trialing', 'active', 'past_due'], true)) {
            if ($tier === 'pro') {
                return 'pro';
            }
            if ($tier === 'elite') {
                return 'elite';
            }
        }

        return 'free';
    }

    public function getListCap(string $tier): ?int
    {
        return array_key_exists($tier, EntitlementEnum::LIST_CAPS) 
            ? EntitlementEnum::LIST_CAPS[$tier] 
            : 0;
    }

    public function getMetaColumns(string $tier): array
    {
        return EntitlementEnum::META_COLUMNS[$tier] ?? [];
    }
}
