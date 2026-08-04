import { Head } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { campaigns } from '@/routes';

type Props = {
    campaignCount: number;
    recipientCount: number;
};

export default function CampaignsIndex({
    campaignCount,
    recipientCount,
}: Props) {
    return (
        <>
            <Head title="Campaigns" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Campaigns"
                    description="Broadcast to a segment safely — inside rate limits, consent, and budget."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Campaigns" value={campaignCount} />
                    <StatCard label="Recipients" value={recipientCount} />
                </div>
                <EmptyState
                    icon={Megaphone}
                    title="No campaigns yet"
                    description="Pick a segment, a template, and a schedule. Campaigns respect consent, Meta's rate limits, and your budget caps — with per-recipient delivery reporting."
                />
            </div>
        </>
    );
}

CampaignsIndex.layout = (props: { team?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Campaigns',
            href: props.team ? campaigns(props.team.slug) : '/',
        },
    ],
});
