import PendingInvitationsList from '@/components/pending-invitations-list';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { PendingInvitation } from '@/types';

type Props = {
    invitations: PendingInvitation[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function PendingInvitationsModal({
    invitations,
    open,
    onOpenChange,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent data-test="pending-invitations-modal">
                <DialogHeader>
                    <DialogTitle>Pending team invitations</DialogTitle>
                    <DialogDescription>
                        You already belong to a team, so these can only be
                        declined. A person can belong to one team at a time.
                    </DialogDescription>
                </DialogHeader>

                <PendingInvitationsList
                    invitations={invitations}
                    canAccept={false}
                    onResponded={() => {
                        if (invitations.length === 1) {
                            onOpenChange(false);
                        }
                    }}
                />
            </DialogContent>
        </Dialog>
    );
}
