<?php

namespace App\Traits;

use App\Enums\EntitlementEnum;

trait EntitlementTrait
{
    public function getEntitlement(?object $user): array
    {
        $entitled = $user !== null && in_array($user->plan_status ?? null, ['trialing', 'active'], true);

        if (!$entitled) {
            return ['tier' => null, 'list_cap' => 0, 'row_cap' => EntitlementEnum::GUEST_ROW_CAP];
        }

        $tier = $user->selected_tier ?? null;

        if ($tier === 'tier1') {
            return ['tier' => 'tier1', 'list_cap' => EntitlementEnum::TIER1_LIST_CAP, 'row_cap' => null];
        }

        if ($tier === 'tier2') {
            return ['tier' => 'tier2', 'list_cap' => null, 'row_cap' => null];
        }

        // Entitled but tier missing/unknown — defensive fallback
        return ['tier' => null, 'list_cap' => 0, 'row_cap' => EntitlementEnum::GUEST_ROW_CAP];
    }
}
