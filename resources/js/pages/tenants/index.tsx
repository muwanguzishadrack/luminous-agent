import { Head, Link } from '@inertiajs/react';
import { Eye, LogOut, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import CreateTenantModal from '@/components/create-tenant-modal';
import Heading from '@/components/heading';
import LeaveTenantModal from '@/components/leave-tenant-modal';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { edit, index } from '@/routes/tenants';
import type { Tenant } from '@/types';

type Props = {
    tenants: Tenant[];
};

export default function TenantsIndex({ tenants }: Props) {
    const [leaveTenantDialogOpen, setLeaveTenantDialogOpen] = useState(false);
    const [tenantLeaving, setTenantLeaving] = useState<Tenant | null>(null);

    const openLeaveTenantDialog = (tenant: Tenant) => {
        setTenantLeaving(tenant);
        setLeaveTenantDialogOpen(true);
    };

    return (
        <>
            <Head title="Tenants" />

            <h1 className="sr-only">Tenants</h1>

            <div className="flex flex-col space-y-6">
                <div className="flex items-center justify-between">
                    <Heading
                        variant="small"
                        title="Tenants"
                        description="Manage your tenants and tenant memberships"
                    />

                    <CreateTenantModal>
                        <Button data-test="tenants-new-tenant-button">
                            <Plus /> New tenant
                        </Button>
                    </CreateTenantModal>
                </div>

                <div className="space-y-3">
                    {tenants.map((tenant) => {
                        const canLeaveTenant =
                            !tenant.isPersonal && tenant.role !== 'owner';

                        return (
                            <div
                                key={tenant.id}
                                data-test="tenant-row"
                                className="flex items-center justify-between gap-4 rounded-lg border p-4"
                            >
                                <div className="flex items-center gap-4">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">
                                                {tenant.name}
                                            </span>
                                            {tenant.isPersonal ? (
                                                <Badge variant="secondary">
                                                    Personal
                                                </Badge>
                                            ) : null}
                                        </div>
                                        <span className="text-sm text-muted-foreground">
                                            {tenant.roleLabel}
                                        </span>
                                    </div>
                                </div>

                                <TooltipProvider>
                                    <div className="flex items-center gap-2">
                                        {canLeaveTenant ? (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="tenant-leave-button"
                                                        onClick={() =>
                                                            openLeaveTenantDialog(
                                                                tenant,
                                                            )
                                                        }
                                                    >
                                                        <LogOut className="h-4 w-4" />
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>Leave tenant</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        ) : null}

                                        {tenant.role !== 'owner' &&
                                        tenant.role !== 'admin' ? (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="tenant-view-button"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={edit(
                                                                tenant.slug,
                                                            )}
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>View tenant</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        ) : (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        data-test="tenant-edit-button"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={edit(
                                                                tenant.slug,
                                                            )}
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    <p>Edit tenant</p>
                                                </TooltipContent>
                                            </Tooltip>
                                        )}
                                    </div>
                                </TooltipProvider>
                            </div>
                        );
                    })}

                    {tenants.length === 0 ? (
                        <p className="py-8 text-center text-muted-foreground">
                            You don't belong to any tenants yet.
                        </p>
                    ) : null}
                </div>
            </div>

            <LeaveTenantModal
                tenant={tenantLeaving}
                open={leaveTenantDialogOpen}
                onOpenChange={setLeaveTenantDialogOpen}
            />
        </>
    );
}

TenantsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Tenants',
            href: index(),
        },
    ],
};
