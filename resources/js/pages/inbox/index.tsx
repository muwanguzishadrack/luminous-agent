import { Head } from '@inertiajs/react';
import { MessagesSquare } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { inbox } from '@/routes';

type Props = {
    conversationCount: number;
    messageCount: number;
};

export default function InboxIndex({ conversationCount, messageCount }: Props) {
    return (
        <>
            <Head title="Inbox" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Inbox"
                    description="Every WhatsApp conversation your team handles, in one shared inbox."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Conversations" value={conversationCount} />
                    <StatCard label="Messages" value={messageCount} />
                </div>
                <EmptyState
                    icon={MessagesSquare}
                    title="No conversations yet"
                    description="Once your WhatsApp number is connected, every inbound message lands here — assignable to agents, with labels, notes, and canned replies, so no message is ever lost."
                />
            </div>
        </>
    );
}

InboxIndex.layout = (props: { currentTenant?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Inbox',
            href: props.currentTenant ? inbox(props.currentTenant.slug) : '/',
        },
    ],
});
