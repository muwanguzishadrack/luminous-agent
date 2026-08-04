import { Head } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { contacts } from '@/routes';

type Props = {
    contactCount: number;
    labelCount: number;
};

export default function ContactsIndex({ contactCount, labelCount }: Props) {
    return (
        <>
            <Head title="Contacts" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Contacts"
                    description="Everyone you talk to on WhatsApp, with consent tracked on every contact."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Contacts" value={contactCount} />
                    <StatCard label="Labels" value={labelCount} />
                </div>
                <EmptyState
                    icon={Users}
                    title="No contacts yet"
                    description="Contacts are created automatically from inbound messages and imports. Know who you are talking to, track opt-ins and opt-outs, and never message someone who opted out."
                />
            </div>
        </>
    );
}

ContactsIndex.layout = (props: { team?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Contacts',
            href: props.team ? contacts(props.team.slug) : '/',
        },
    ],
});
