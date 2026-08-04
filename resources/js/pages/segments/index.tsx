import { Head } from '@inertiajs/react';
import { Filter } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { segments } from '@/routes';

type Props = {
    segmentCount: number;
};

export default function SegmentsIndex({ segmentCount }: Props) {
    return (
        <>
            <Head title="Segments" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Segments"
                    description="Reusable audience filters that decide exactly who a campaign reaches."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Segments" value={segmentCount} />
                </div>
                <EmptyState
                    icon={Filter}
                    title="No segments yet"
                    description="Build filters over contact fields, labels, and activity to create reusable audiences. Campaigns and sequences target segments — never raw lists."
                />
            </div>
        </>
    );
}

SegmentsIndex.layout = (props: { team?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Segments',
            href: props.team ? segments(props.team.slug) : '/',
        },
    ],
});
