import { Form } from '@inertiajs/react';
import { ExternalLink, RefreshCw } from 'lucide-react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { refresh } from '@/routes/whatsapp';
import type { WhatsAppAccount, WhatsAppPhoneNumber } from '@/types';

type Props = {
    account: WhatsAppAccount;
    number: WhatsAppPhoneNumber;
    canManage: boolean;
    whatsappManagerUrl: string;
};

const QUALITY_STYLES: Record<string, string> = {
    GREEN: 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    YELLOW: 'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400',
    RED: 'border-transparent bg-red-500/15 text-red-700 dark:text-red-400',
    NA: 'border-transparent bg-muted text-muted-foreground',
    // New numbers come back UNKNOWN until Meta has enough delivery data.
    UNKNOWN: 'border-transparent bg-muted text-muted-foreground',
};

/**
 * Display-name review states. `EXPIRED` lives here — it is a `name_status`
 * value, never a two-step verification value.
 */
const NAME_STATUS_STYLES: Record<string, string> = {
    APPROVED:
        'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    AVAILABLE_WITHOUT_REVIEW:
        'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    PENDING_REVIEW:
        'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400',
    DECLINED: 'border-transparent bg-red-500/15 text-red-700 dark:text-red-400',
    EXPIRED: 'border-transparent bg-red-500/15 text-red-700 dark:text-red-400',
    NONE: 'border-transparent bg-muted text-muted-foreground',
};

const NAME_STATUS_LABELS: Record<string, string> = {
    APPROVED: 'Approved',
    AVAILABLE_WITHOUT_REVIEW: 'Available without review',
    PENDING_REVIEW: 'Pending review',
    DECLINED: 'Declined',
    EXPIRED: 'Expired',
    NONE: 'Not set',
};

const QUALITY_LABELS: Record<string, string> = {
    NA: 'Not available',
    UNKNOWN: 'Not yet rated',
};

function formatTier(tier: string): string {
    if (!tier.startsWith('TIER_')) {
        return formatEnum(tier);
    }

    const value = tier.replace('TIER_', '');

    return value === 'UNLIMITED'
        ? 'Unlimited'
        : `${value.toLowerCase()} customers / 24 hours`;
}

function formatEnum(value: string): string {
    const lower = value.replaceAll('_', ' ').toLowerCase();

    return lower.charAt(0).toUpperCase() + lower.slice(1);
}

function formatThroughput(level: string): string {
    if (level === 'STANDARD') {
        return 'Standard (80 messages / second)';
    }

    if (level === 'HIGH') {
        return 'High (1,000 messages / second)';
    }

    return formatEnum(level);
}

function formatSyncedAt(timestamp: string | null): string {
    if (timestamp === null) {
        return 'Never synced';
    }

    return `Last synced ${new Date(timestamp).toLocaleString()}`;
}

function Field({
    label,
    hint,
    children,
}: {
    label: string;
    hint?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1">
            <dt className="text-xs font-medium text-muted-foreground">
                {label}
            </dt>
            <dd className="text-sm break-words">{children}</dd>
            {hint ? (
                <p className="text-xs text-muted-foreground">{hint}</p>
            ) : null}
        </div>
    );
}

export default function WhatsAppConnectionPanel({
    account,
    number,
    canManage,
    whatsappManagerUrl,
}: Props) {
    return (
        <section className="space-y-4" aria-label="WhatsApp account connected">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <Heading
                    variant="small"
                    title="WhatsApp account connected"
                    description="Read from Meta. Everything here is managed in WhatsApp Manager, not in Luminous."
                />

                <div className="flex items-center gap-3">
                    <span
                        className="text-xs text-muted-foreground"
                        data-test="whatsapp-last-synced"
                    >
                        {formatSyncedAt(number.lastSyncedAt)}
                    </span>

                    {canManage ? (
                        <Form
                            {...refresh.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    variant="outline"
                                    size="sm"
                                    data-test="whatsapp-refresh-button"
                                    disabled={processing}
                                >
                                    <RefreshCw
                                        className={cn(
                                            'size-4',
                                            processing && 'animate-spin',
                                        )}
                                    />
                                    Refresh
                                </Button>
                            )}
                        </Form>
                    ) : null}
                </div>
            </div>

            <dl className="grid grid-cols-1 gap-x-6 gap-y-4 rounded-lg border p-4 sm:grid-cols-2">
                <Field label="Connected number">
                    <span className="font-medium">
                        {number.displayPhoneNumber}
                    </span>
                </Field>

                {/* verified_name is read-only here on purpose: the business
                    profile endpoint has no name parameter, and changing the
                    display name means a separate Meta review. */}
                <Field
                    label="Display name"
                    hint="Changing this requires a display-name review by Meta, in WhatsApp Manager."
                >
                    {number.verifiedName}
                </Field>

                <Field label="Quality rating">
                    <Badge
                        variant="outline"
                        className={cn(QUALITY_STYLES[number.qualityRating])}
                        data-test="whatsapp-quality-rating"
                    >
                        {QUALITY_LABELS[number.qualityRating] ??
                            formatEnum(number.qualityRating)}
                    </Badge>
                </Field>

                {/* code_verification_status — VERIFIED or UNVERIFIED, nothing
                    else. Never labelled as a display-name state. */}
                <Field label="Two-step verification">
                    <Badge
                        variant={
                            number.codeVerificationStatus === 'VERIFIED'
                                ? 'outline'
                                : 'destructive'
                        }
                        data-test="whatsapp-code-verification-status"
                    >
                        {formatEnum(number.codeVerificationStatus)}
                    </Badge>
                </Field>

                {/* name_status — this is where EXPIRED belongs. */}
                <Field label="Display name status">
                    <Badge
                        variant="outline"
                        className={cn(NAME_STATUS_STYLES[number.nameStatus])}
                        data-test="whatsapp-name-status"
                    >
                        {NAME_STATUS_LABELS[number.nameStatus] ??
                            formatEnum(number.nameStatus)}
                    </Badge>
                </Field>

                <Field label="WhatsApp business account ID">
                    <span className="font-mono text-xs">{account.wabaId}</span>
                </Field>

                {/* Portfolio-level, not per-number: messaging_limit_tier
                    was deprecated by Meta on 2026-05-21 and returns nothing
                    on v24.0+. */}
                <Field label="Messaging limit">
                    {account.portfolioMessagingLimit === null
                        ? 'Not yet assigned'
                        : formatTier(account.portfolioMessagingLimit)}
                </Field>

                <Field label="Account status">
                    {formatEnum(account.accountReviewStatus)}
                </Field>

                <Field label="Throughput">
                    {formatThroughput(number.throughputLevel)}
                </Field>

                <Field
                    label="Platform"
                    hint={
                        number.isOnBizApp
                            ? 'This number is still linked to the WhatsApp Business app.'
                            : undefined
                    }
                >
                    <span className="flex flex-wrap items-center gap-1.5">
                        {formatEnum(number.platformType)}
                        {number.isOnBizApp ? (
                            <Badge data-test="whatsapp-coexistence-badge">
                                Coexistence
                            </Badge>
                        ) : null}
                    </span>
                </Field>
            </dl>

            <Button asChild variant="link" size="sm" className="h-auto px-0">
                <a
                    href={whatsappManagerUrl}
                    target="_blank"
                    rel="noreferrer noopener"
                >
                    Open WhatsApp Manager
                    <ExternalLink className="size-3.5" />
                </a>
            </Button>
        </section>
    );
}
