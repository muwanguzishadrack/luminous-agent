import { Head } from '@inertiajs/react';
import { Workflow } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { sequences } from '@/routes';

type Props = {
    sequenceCount: number;
    enrollmentCount: number;
};

export default function SequencesIndex({
    sequenceCount,
    enrollmentCount,
}: Props) {
    return (
        <>
            <Head title="Sequences" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Sequences"
                    description="Multi-step message journeys that enroll contacts and send on a schedule."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Sequences" value={sequenceCount} />
                    <StatCard label="Enrollments" value={enrollmentCount} />
                </div>
                <EmptyState
                    icon={Workflow}
                    title="No sequences yet"
                    description="Chain template sends into automated journeys. Contacts enroll from segments or events and progress step by step until they convert or exit."
                />
            </div>
        </>
    );
}

SequencesIndex.layout = (props: {
    currentTenant?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Sequences',
            href: props.currentTenant
                ? sequences(props.currentTenant.slug)
                : '/',
        },
    ],
});
