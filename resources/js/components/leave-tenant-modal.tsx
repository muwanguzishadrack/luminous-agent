import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { leave as leaveTenantAction } from '@/routes/tenants';
import type { Tenant } from '@/types';

type Props = {
    tenant: Tenant | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function LeaveTenantModal({
    tenant,
    open,
    onOpenChange,
}: Props) {
    const [processing, setProcessing] = useState(false);

    const leaveTenant = () => {
        if (!tenant) {
            return;
        }

        router.visit(leaveTenantAction(tenant.slug), {
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Leave tenant</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to leave{' '}
                        <strong>{tenant?.name}</strong>?
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>

                    <Button
                        variant="destructive"
                        data-test="leave-tenant-confirm"
                        disabled={processing}
                        onClick={leaveTenant}
                    >
                        Leave tenant
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
