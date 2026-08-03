import { Head, Link } from '@inertiajs/react';
import { CircleAlert, Phone } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { index as numbersIndex } from '@/routes/numbers';
import { index as onboardingIndex } from '@/routes/onboarding';

type PhoneNumberProp = {
    id: string;
    displayPhoneNumber: string;
    verifiedName: string;
    qualityRating: string;
    messagingLimitTier: string;
    throughputLevel: string;
    platformType: string;
    isOnBizApp: boolean;
    pinSet: boolean;
    status: string;
};

type WabaAccountProp = {
    id: string;
    name: string;
    wabaId: string;
    paymentReady: boolean;
    phoneNumbers: PhoneNumberProp[];
};

type Props = {
    wabaAccounts: WabaAccountProp[];
};

const QUALITY_STYLES: Record<string, string> = {
    GREEN: 'border-transparent bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    YELLOW: 'border-transparent bg-amber-500/15 text-amber-700 dark:text-amber-400',
    RED: 'border-transparent bg-red-500/15 text-red-700 dark:text-red-400',
};

function formatTier(tier: string): string {
    return `Tier ${tier.replace('TIER_', '').toLowerCase()}`;
}

function formatEnum(value: string): string {
    const lower = value.replaceAll('_', ' ').toLowerCase();

    return lower.charAt(0).toUpperCase() + lower.slice(1);
}

function PhoneNumberRow({ number }: { number: PhoneNumberProp }) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p className="font-medium">{number.displayPhoneNumber}</p>
                    <p className="text-sm text-muted-foreground">
                        {number.verifiedName}
                    </p>
                </div>
                <Badge
                    variant="outline"
                    className={cn(QUALITY_STYLES[number.qualityRating])}
                >
                    Quality: {formatEnum(number.qualityRating)}
                </Badge>
            </div>
            <div className="flex flex-wrap gap-1.5">
                <Badge variant="secondary">
                    {formatTier(number.messagingLimitTier)}
                </Badge>
                <Badge variant="secondary">
                    {formatEnum(number.throughputLevel)} throughput
                </Badge>
                <Badge variant={number.isOnBizApp ? 'default' : 'outline'}>
                    {number.isOnBizApp
                        ? 'Coexistence'
                        : formatEnum(number.platformType)}
                </Badge>
                <Badge variant={number.pinSet ? 'outline' : 'destructive'}>
                    {number.pinSet ? 'PIN set' : 'PIN not set'}
                </Badge>
            </div>
        </div>
    );
}

export default function Numbers({ wabaAccounts }: Props) {
    return (
        <>
            <Head title="WhatsApp numbers" />

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="WhatsApp numbers"
                    description="The numbers connected to this workspace, with their quality, messaging limits and platform."
                />

                {wabaAccounts.length === 0 ? (
                    <EmptyState
                        icon={Phone}
                        title="No numbers connected"
                        description="Connect a WhatsApp Business number through Embedded Signup to start messaging from this workspace."
                    >
                        <Button asChild className="mt-2">
                            <Link href={onboardingIndex()}>
                                Connect WhatsApp
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    wabaAccounts.map((wabaAccount) => (
                        <section
                            key={wabaAccount.id}
                            className="space-y-3"
                            aria-label={wabaAccount.name}
                        >
                            <div>
                                <h3 className="text-sm font-medium">
                                    {wabaAccount.name}
                                </h3>
                                <p className="text-xs text-muted-foreground">
                                    WABA {wabaAccount.wabaId}
                                </p>
                            </div>

                            {!wabaAccount.paymentReady && (
                                <Alert variant="destructive">
                                    <CircleAlert className="size-4" />
                                    <AlertTitle>
                                        Payment method required
                                    </AlertTitle>
                                    <AlertDescription>
                                        Meta bills this account directly for
                                        messaging. Attach a payment method to
                                        this WhatsApp Business Account in Meta's
                                        WhatsApp Manager before sending
                                        campaigns.
                                    </AlertDescription>
                                </Alert>
                            )}

                            <div className="space-y-2">
                                {wabaAccount.phoneNumbers.map((number) => (
                                    <PhoneNumberRow
                                        key={number.id}
                                        number={number}
                                    />
                                ))}
                            </div>
                        </section>
                    ))
                )}
            </div>
        </>
    );
}

Numbers.layout = {
    breadcrumbs: [
        {
            title: 'WhatsApp numbers',
            href: numbersIndex(),
        },
    ],
};
