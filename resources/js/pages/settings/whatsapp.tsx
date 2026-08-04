import { Head, Link, usePage } from '@inertiajs/react';
import { CircleAlert, Phone } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import WhatsAppBillingCard from '@/components/whatsapp-billing-card';
import WhatsAppConnectionPanel from '@/components/whatsapp-connection-panel';
import WhatsAppDisconnectCard from '@/components/whatsapp-disconnect-card';
import WhatsAppProfileForm from '@/components/whatsapp-profile-form';
import { index as onboardingIndex } from '@/routes/onboarding';
import { show as whatsappShow } from '@/routes/whatsapp';
import type {
    WhatsAppAccount,
    WhatsAppBusinessProfile,
    WhatsAppLinks,
    WhatsAppPhoneNumber,
    WhatsAppVerticalOption,
} from '@/types';

type Props = {
    wabaAccount: WhatsAppAccount | null;
    phoneNumber: WhatsAppPhoneNumber | null;
    profile: WhatsAppBusinessProfile | null;
    verticals: WhatsAppVerticalOption[];
    canManage: boolean;
    links: WhatsAppLinks;
};

export default function WhatsAppSettings({
    wabaAccount,
    phoneNumber,
    profile,
    verticals,
    canManage,
    links,
}: Props) {
    // Graph refusals and Coexistence branches come back as a `meta` error on
    // the page rather than a 500 (docs/modules/m0-onboarding.md §2).
    const metaError = usePage().props.errors?.meta;

    return (
        <>
            <Head title="WhatsApp" />

            <h1 className="sr-only">WhatsApp settings</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="WhatsApp"
                    description="This team's WhatsApp Business Account and number, its business profile, billing and disconnection."
                />

                {metaError ? (
                    <Alert
                        variant="destructive"
                        data-test="whatsapp-meta-error"
                    >
                        <CircleAlert className="size-4" />
                        <AlertTitle>
                            WhatsApp could not complete that
                        </AlertTitle>
                        <AlertDescription>
                            <p>{metaError}</p>
                        </AlertDescription>
                    </Alert>
                ) : null}

                {wabaAccount === null || phoneNumber === null ? (
                    <EmptyState
                        icon={Phone}
                        title="WhatsApp is not connected"
                        description="Connect a WhatsApp Business number through Embedded Signup to start messaging from this team."
                    >
                        <Button asChild className="mt-2">
                            <Link href={onboardingIndex()}>
                                Connect WhatsApp
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <div className="space-y-10">
                        <WhatsAppConnectionPanel
                            account={wabaAccount}
                            number={phoneNumber}
                            canManage={canManage}
                            whatsappManagerUrl={links.whatsappManager}
                        />

                        <Separator />

                        {profile ? (
                            <WhatsAppProfileForm
                                profile={profile}
                                verticals={verticals}
                                canManage={canManage}
                            />
                        ) : null}

                        <Separator />

                        <WhatsAppBillingCard
                            paymentReady={wabaAccount.paymentReady}
                            billingUrl={links.billing}
                        />

                        <Separator />

                        <WhatsAppDisconnectCard
                            number={phoneNumber}
                            canManage={canManage}
                        />
                    </div>
                )}
            </div>
        </>
    );
}

WhatsAppSettings.layout = {
    breadcrumbs: [
        {
            title: 'WhatsApp',
            href: whatsappShow(),
        },
    ],
};
