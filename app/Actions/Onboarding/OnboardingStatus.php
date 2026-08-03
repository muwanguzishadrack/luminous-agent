<?php

namespace App\Actions\Onboarding;

/**
 * The enum-ish onboarding_sessions.status flow (docs/02, platform tables):
 * started → finished → exchanged → registered → syncing → complete | failed.
 * Plus the Embedded Signup v4 constants the flow keys off
 * (docs/modules/m0-onboarding.md §1, §3).
 */
final class OnboardingStatus
{
    public const STARTED = 'started';

    public const FINISHED = 'finished';

    public const EXCHANGED = 'exchanged';

    public const REGISTERED = 'registered';

    public const SYNCING = 'syncing';

    public const COMPLETE = 'complete';

    public const FAILED = 'failed';

    /**
     * The ES `extras.featureType` marking a Coexistence onboarding — the
     * number is already registered on the WhatsApp Business app.
     */
    public const COEXISTENCE_FEATURE = 'whatsapp_business_app_onboarding';

    /**
     * The Coexistence variant of the ES FINISH session event.
     */
    public const COEXISTENCE_FINISH_EVENT = 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING';
}
