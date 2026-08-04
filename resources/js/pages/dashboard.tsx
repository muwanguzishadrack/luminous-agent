import { Head, Link } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { PlaceholderPattern } from '@/components/ui/placeholder-pattern';
import { dashboard } from '@/routes';
import { index as onboardingIndex } from '@/routes/onboarding';
import type { PendingInvitation } from '@/types';

type Props = {
    pendingInvitations?: PendingInvitation[];
    hasWhatsAppNumbers?: boolean;
};

export default function Dashboard({
    pendingInvitations = [],
    hasWhatsAppNumbers = true,
}: Props) {
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );

    return (
        <>
            <Head title="Dashboard" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
                {!hasWhatsAppNumbers && (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <MessagesSquare className="size-5 text-muted-foreground" />
                                <CardTitle>Connect WhatsApp</CardTitle>
                            </div>
                            <CardDescription>
                                No number is connected to this team yet. Link a
                                WhatsApp Business number to start receiving and
                                sending messages.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Button asChild>
                                <Link href={onboardingIndex()}>
                                    Connect WhatsApp
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                )}
                <div className="grid auto-rows-min gap-4 md:grid-cols-3">
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                    <div className="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                        <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                    </div>
                </div>
                <div className="relative min-h-[100vh] flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                    <PlaceholderPattern className="absolute inset-0 size-full stroke-neutral-900/20 dark:stroke-neutral-100/20" />
                </div>
            </div>
        </>
    );
}

Dashboard.layout = (props: { team?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.team ? dashboard(props.team.slug) : '/',
        },
    ],
});
