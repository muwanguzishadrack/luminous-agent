import { Head } from '@inertiajs/react';
import { TrendingUp } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { growth } from '@/routes';

type Props = {
    referralCount: number;
    conversionCount: number;
};

export default function GrowthIndex({ referralCount, conversionCount }: Props) {
    return (
        <>
            <Head title="Growth" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Growth"
                    description="Attribute every lead to the ad that produced it, and report conversions back to Meta."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Ad referrals" value={referralCount} />
                    <StatCard label="Conversions" value={conversionCount} />
                </div>
                <EmptyState
                    icon={TrendingUp}
                    title="No ad activity yet"
                    description="When Click-to-WhatsApp ads and tracked links start driving conversations, referrals, conversions, and closed-loop ROAS reporting will show up here."
                />
            </div>
        </>
    );
}

GrowthIndex.layout = (props: { team?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Growth',
            href: props.team ? growth(props.team.slug) : '/',
        },
    ],
});
