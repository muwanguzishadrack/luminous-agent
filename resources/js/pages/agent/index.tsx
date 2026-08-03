import { Head } from '@inertiajs/react';
import { Bot } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { agent } from '@/routes';

type Props = {
    agentCount: number;
    knowledgeSourceCount: number;
};

export default function AgentIndex({
    agentCount,
    knowledgeSourceCount,
}: Props) {
    return (
        <>
            <Head title="AI Agent" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="AI Agent"
                    description="Meta's AI answers first — with your CRM's knowledge of the customer — and hands off cleanly."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Agents" value={agentCount} />
                    <StatCard
                        label="Knowledge sources"
                        value={knowledgeSourceCount}
                    />
                </div>
                <EmptyState
                    icon={Bot}
                    title="No agent configured"
                    description="Connect the Meta Business Agent to answer common questions using your knowledge sources and CRM context, then hand the thread to a human when it matters."
                />
            </div>
        </>
    );
}

AgentIndex.layout = (props: { currentTenant?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'AI Agent',
            href: props.currentTenant ? agent(props.currentTenant.slug) : '/',
        },
    ],
});
