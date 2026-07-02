<?php

namespace App\Enums;

use Library\Enum;

class PlanEnum extends Enum
{
    const TRIAL_DAYS = 14;

    const PRICES = [
        'pro_monthly'   => ['amount' => 499,  'interval' => 'monthly', 'currency' => 'INR'],
        'pro_yearly'    => ['amount' => 4990, 'interval' => 'yearly',  'currency' => 'INR'],
        'elite_monthly' => ['amount' => 999,  'interval' => 'monthly', 'currency' => 'INR'],
        'elite_yearly'  => ['amount' => 9990, 'interval' => 'yearly',  'currency' => 'INR'],
    ];

    const HIGHEST_TIER = 'elite';
}
