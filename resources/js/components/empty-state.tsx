import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
    icon: Icon,
    title,
    description,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    children?: ReactNode;
}) {
    return (
        <div className="flex flex-1 items-center justify-center rounded-xl border border-dashed border-sidebar-border/70 p-8 dark:border-sidebar-border">
            <div className="flex max-w-md flex-col items-center gap-2 text-center">
                <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                    <Icon className="size-6 text-muted-foreground" />
                </div>
                <h3 className="mt-2 text-lg font-semibold">{title}</h3>
                <p className="text-sm text-balance text-muted-foreground">
                    {description}
                </p>
                {children}
            </div>
        </div>
    );
}
