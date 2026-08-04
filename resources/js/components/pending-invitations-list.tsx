import { router } from '@inertiajs/react';
import { useState } from 'react';
import TeamInvitationController from '@/actions/App/Http/Controllers/Teams/TeamInvitationController';
import { Button } from '@/components/ui/button';
import type { PendingInvitation } from '@/types';

type Props = {
    invitations: PendingInvitation[];
    /**
     * False once the user already belongs to a team — one team per user, so
     * the only honest action left is to decline.
     */
    canAccept: boolean;
    onResponded?: () => void;
};

/**
 * The accept/decline rows, shared by the standalone invitations page and the
 * dashboard modal so the two can never drift.
 */
export default function PendingInvitationsList({
    invitations,
    canAccept,
    onResponded,
}: Props) {
    const [processingCode, setProcessingCode] = useState<string | null>(null);

    // accept is a GET and decline a DELETE, so they cannot share a callable
    // type — only the visit options are common.
    const optionsFor = (invitation: PendingInvitation) => ({
        onStart: () => setProcessingCode(invitation.code),
        onFinish: () => setProcessingCode(null),
        onSuccess: () => onResponded?.(),
    });

    const accept = (invitation: PendingInvitation) =>
        router.visit(
            TeamInvitationController.accept(invitation),
            optionsFor(invitation),
        );

    const decline = (invitation: PendingInvitation) =>
        router.visit(
            TeamInvitationController.decline(invitation),
            optionsFor(invitation),
        );

    return (
        <div className="grid gap-4">
            {invitations.map((invitation) => (
                <div
                    key={invitation.code}
                    data-test="pending-invitation-row"
                    className="rounded-lg border p-4"
                >
                    <div className="space-y-1">
                        <p className="font-medium">{invitation.teamName}</p>
                        <p className="text-sm text-muted-foreground">
                            {invitation.inviterName} invited you to join as{' '}
                            {invitation.roleLabel}.
                        </p>
                    </div>

                    <div className="mt-4 flex justify-end gap-2">
                        <Button
                            variant="secondary"
                            data-test="pending-invitation-decline"
                            disabled={processingCode === invitation.code}
                            onClick={() => decline(invitation)}
                        >
                            Decline
                        </Button>

                        {canAccept && (
                            <Button
                                data-test="pending-invitation-accept"
                                disabled={processingCode === invitation.code}
                                onClick={() => accept(invitation)}
                            >
                                Accept
                            </Button>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}
