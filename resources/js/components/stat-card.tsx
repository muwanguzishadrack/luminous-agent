import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export function StatCard({ label, value }: { label: string; value: number }) {
    return (
        <Card className="gap-0 py-4">
            <CardHeader className="gap-1 px-4">
                <CardDescription>{label}</CardDescription>
                <CardTitle className="text-2xl tabular-nums">
                    {value.toLocaleString()}
                </CardTitle>
            </CardHeader>
        </Card>
    );
}
