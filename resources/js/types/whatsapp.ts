/**
 * The team's single WhatsApp connection (D-020). Field names and value sets
 * come from docs/reference/whatsapp-cloud-api.md §5 — in particular
 * `codeVerificationStatus` (two-step verification) and `nameStatus`
 * (display-name review) are separate fields and are never rendered under one
 * another's label.
 */
export type WhatsAppQualityRating =
    | 'GREEN'
    | 'YELLOW'
    | 'RED'
    | 'NA'
    // A number Meta has not rated yet — new numbers come back UNKNOWN.
    | 'UNKNOWN';

export type WhatsAppNameStatus =
    | 'APPROVED'
    | 'AVAILABLE_WITHOUT_REVIEW'
    | 'DECLINED'
    | 'EXPIRED'
    | 'PENDING_REVIEW'
    | 'NONE';

export type WhatsAppCodeVerificationStatus = 'VERIFIED' | 'UNVERIFIED';

export type WhatsAppPhoneNumber = {
    id: string;
    displayPhoneNumber: string;
    verifiedName: string;
    qualityRating: WhatsAppQualityRating | string;
    throughputLevel: string;
    platformType: string;
    isOnBizApp: boolean;
    codeVerificationStatus: WhatsAppCodeVerificationStatus | string;
    nameStatus: WhatsAppNameStatus | string;
    pinSet: boolean;
    status: string;
    lastSyncedAt: string | null;
};

export type WhatsAppAccount = {
    id: string;
    name: string;
    wabaId: string;
    paymentReady: boolean;
    accountReviewStatus: string;
    businessVerificationStatus: string;
    /**
     * The business portfolio's messaging limit, from
     * `whatsapp_business_manager_messaging_limit`. Null until Meta assigns
     * one. The per-number `messaging_limit_tier` it replaced was deprecated
     * on 2026-05-21 and returns nothing on v24.0+.
     */
    portfolioMessagingLimit: string | null;
};

export type WhatsAppBusinessProfile = {
    about: string | null;
    address: string | null;
    email: string | null;
    description: string | null;
    vertical: string | null;
    /** Exactly two slots — two websites is a hard Meta limit. */
    websites: string[];
    /** Read-only: the write side takes a Resumable Upload handle instead. */
    profilePictureUrl: string | null;
};

export type WhatsAppVerticalOption = {
    value: string;
    label: string;
};

export type WhatsAppLinks = {
    whatsappManager: string;
    billing: string;
};
