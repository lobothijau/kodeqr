<?php

declare(strict_types=1);

use App\Enums\Package;
use App\Enums\Plan;
use App\Services\PlanConfig;

/*
|--------------------------------------------------------------------------
| Plan entitlements and prices
|--------------------------------------------------------------------------
|
| The single source of truth for what each plan may do and what it costs
| (constraints 6 and 7). Nothing outside this file may name a plan or an
| amount; everything reads it through App\Services\Entitlements.
|
| Keyed by App\Enums\Plan values. `free` (never paid) and `lapsed` (paid
| before, package expired) are entitlement sets, not products — neither is
| storable on a subscriptions row and neither carries prices.
|
| Money is integer rupiah. The launch price table is mirrored in
| documentation/billing.md; change it here first, then update that table in
| the same commit.
|
| Limits: null means unlimited. `retention_days` on `lapsed` is the one
| exception — PlanConfig::INHERIT reads the value from the expired
| subscription's own tier, so a lapsed Business customer's history survives
| until renewal instead of being pruned to the free tier's 7 days (M5-T3).
|
*/

return [

    Plan::Free->value => [
        'max_codes' => 3,
        'scan_cap_per_code' => 500,
        'retention_days' => 7,
        'can_edit' => true,
        'interstitial' => true,
        'records_scans' => true,
        'styling' => false,
        'vector_export' => false,
        'file_qr' => false,
        'bulk' => false,
        'api_quota' => 0,
        'analytics_depth' => 'basic',
        'seats' => 1,
        'prices' => [],
    ],

    // Everything the customer was paying for is off; the codes themselves are
    // untouched and keep redirecting behind the splash forever (constraint 8).
    Plan::Lapsed->value => [
        'max_codes' => 0,
        'scan_cap_per_code' => null,
        'retention_days' => PlanConfig::INHERIT,
        'can_edit' => false,
        // The one plan whose scans are not recorded at all. Serving a lapsed
        // redirect has to stay close to free — a Valkey hit and a response — because
        // it is served forever for nothing (documentation/billing.md).
        'interstitial' => true,
        'records_scans' => false,
        'styling' => false,
        'vector_export' => false,
        'file_qr' => false,
        'bulk' => false,
        'api_quota' => 0,
        'analytics_depth' => null,
        'seats' => 1,
        'prices' => [],
    ],

    Plan::Regular->value => [
        'max_codes' => 10,
        'scan_cap_per_code' => null,
        'retention_days' => 365,
        'can_edit' => true,
        'interstitial' => false,
        'records_scans' => true,
        'styling' => true,
        'vector_export' => true,
        'file_qr' => true,
        'bulk' => false,
        'api_quota' => 0,
        'analytics_depth' => 'basic',
        'seats' => 1,
        'prices' => [
            Package::ThreeMonths->value => 149_000,
            Package::SixMonths->value => 269_000,
            Package::TwelveMonths->value => 490_000,
        ],
    ],

    Plan::Plus->value => [
        'max_codes' => 100,
        'scan_cap_per_code' => null,
        'retention_days' => 365,
        'can_edit' => true,
        'interstitial' => false,
        'records_scans' => true,
        'styling' => true,
        'vector_export' => true,
        'file_qr' => true,
        'bulk' => true,
        'api_quota' => 3_000,
        'analytics_depth' => 'advanced',
        'seats' => 2,
        'prices' => [
            Package::ThreeMonths->value => 449_000,
            Package::SixMonths->value => 799_000,
            Package::TwelveMonths->value => 1_490_000,
        ],
    ],

    Plan::Business->value => [
        'max_codes' => 500,
        'scan_cap_per_code' => null,
        'retention_days' => null,
        'can_edit' => true,
        'interstitial' => false,
        'records_scans' => true,
        'styling' => true,
        'vector_export' => true,
        'file_qr' => true,
        'bulk' => true,
        'api_quota' => 10_000,
        'analytics_depth' => 'advanced',
        'seats' => 5,
        'prices' => [
            Package::ThreeMonths->value => 1_349_000,
            Package::SixMonths->value => 2_449_000,
            Package::TwelveMonths->value => 4_490_000,
        ],
    ],

];
