import { Head } from '@inertiajs/react';
import { LayoutTemplate } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { templates } from '@/routes';

type Props = {
    templateCount: number;
};

export default function TemplatesIndex({ templateCount }: Props) {
    return (
        <>
            <Head title="Templates" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Templates"
                    description="Create, submit, and monitor WhatsApp templates without touching WhatsApp Manager."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Templates" value={templateCount} />
                </div>
                <EmptyState
                    icon={LayoutTemplate}
                    title="No templates yet"
                    description="Draft message templates, submit them to Meta for approval, and track status, quality, and per-category cost — all without leaving the CRM."
                />
            </div>
        </>
    );
}

TemplatesIndex.layout = (props: { team?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Templates',
            href: props.team ? templates(props.team.slug) : '/',
        },
    ],
});
