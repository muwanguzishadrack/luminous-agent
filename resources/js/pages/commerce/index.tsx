import { Head } from '@inertiajs/react';
import { ShoppingBag } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatCard } from '@/components/stat-card';
import { commerce } from '@/routes';

type Props = {
    productCount: number;
    orderCount: number;
};

export default function CommerceIndex({ productCount, orderCount }: Props) {
    return (
        <>
            <Head title="Commerce" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Commerce"
                    description="Sell in the conversation and collect payments via MTN and Airtel money."
                />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard label="Products" value={productCount} />
                    <StatCard label="Orders" value={orderCount} />
                </div>
                <EmptyState
                    icon={ShoppingBag}
                    title="No products or orders yet"
                    description="Build a catalog, share products in-thread, and turn conversations into orders paid by mobile money through ioTec Pay — reconciled automatically."
                />
            </div>
        </>
    );
}

CommerceIndex.layout = (props: {
    currentTenant?: { slug: string } | null;
}) => ({
    breadcrumbs: [
        {
            title: 'Commerce',
            href: props.currentTenant
                ? commerce(props.currentTenant.slug)
                : '/',
        },
    ],
});
