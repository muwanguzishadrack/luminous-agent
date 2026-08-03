<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PhoneNumber;
use App\Models\WabaAccount;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The tenant's WhatsApp numbers screen (docs/modules/m0-onboarding.md §7):
 * every WABA with its numbers, quality, tier, throughput, platform and
 * payment readiness. Tenant scoping is automatic (BelongsToTenant + RLS).
 */
class NumbersController extends Controller
{
    /**
     * Display the tenant's WABA accounts and phone numbers.
     */
    public function __invoke(): Response
    {
        $wabaAccounts = WabaAccount::query()
            ->with('phoneNumbers')
            ->orderBy('name')
            ->get()
            ->map(fn (WabaAccount $wabaAccount) => [
                'id' => $wabaAccount->id,
                'name' => $wabaAccount->name,
                'wabaId' => $wabaAccount->waba_id,
                'paymentReady' => $wabaAccount->payment_ready,
                'phoneNumbers' => $wabaAccount->phoneNumbers
                    ->sortBy('display_phone_number')
                    ->values()
                    ->map(fn (PhoneNumber $number) => [
                        'id' => $number->id,
                        'displayPhoneNumber' => $number->display_phone_number,
                        'verifiedName' => $number->verified_name,
                        'qualityRating' => $number->quality_rating,
                        'messagingLimitTier' => $number->messaging_limit_tier,
                        'throughputLevel' => $number->throughput_level,
                        'platformType' => $number->platform_type,
                        'isOnBizApp' => $number->is_on_biz_app,
                        'pinSet' => $number->pin_set,
                        'status' => $number->status,
                    ]),
            ])
            ->values();

        return Inertia::render('settings/numbers', [
            'wabaAccounts' => $wabaAccounts,
        ]);
    }
}
