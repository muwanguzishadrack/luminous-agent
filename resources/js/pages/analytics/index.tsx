import { Head } from '@inertiajs/react';
import { ChartColumn } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { analytics } from '@/routes';

export default function AnalyticsIndex() {
    return (
        <>
            <Head title="Analytics" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Analytics"
                    description="What messaging costs, what it earns, and whether your number is healthy."
                />
                <EmptyState
                    icon={ChartColumn}
                    title="No analytics yet"
                    description="As messaging activity accrues, cost and revenue per conversation, usage meters, and number-health monitoring will appear here."
                />
            </div>
        </>
    );
}

AnalyticsIndex.layout = (props: { team?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Analytics',
            href: props.team ? analytics(props.team.slug) : '/',
        },
    ],
});
