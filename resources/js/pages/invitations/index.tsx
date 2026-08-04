import { Head, Link } from '@inertiajs/react';
import PendingInvitationsList from '@/components/pending-invitations-list';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { edit as editProfile } from '@/routes/profile';
import type { PendingInvitation } from '@/types';

type Props = {
    invitations: PendingInvitation[];
    belongsToTeam: boolean;
};

export default function Invitations({ invitations, belongsToTeam }: Props) {
    return (
        <>
            <Head title="Team invitations" />

            {invitations.length === 0 ? (
                <div
                    data-test="no-pending-invitations"
                    className="space-y-4 text-center"
                >
                    <p className="text-sm text-muted-foreground">
                        You have no pending invitations. Ask whoever invited you
                        to send a new one — invitations expire after three days.
                    </p>
                    <Button asChild variant="secondary">
                        <Link href={editProfile()}>Go to your profile</Link>
                    </Button>
                </div>
            ) : (
                <div className="space-y-4">
                    <PendingInvitationsList
                        invitations={invitations}
                        canAccept={!belongsToTeam}
                    />

                    {belongsToTeam && (
                        <p className="text-center text-sm text-muted-foreground">
                            You already belong to a team, so you can only
                            decline. Leave your current team first if you mean
                            to move.
                        </p>
                    )}

                    <p className="text-center text-sm text-muted-foreground">
                        Not expecting this?{' '}
                        <TextLink href={editProfile()}>
                            Go to your profile
                        </TextLink>{' '}
                        instead.
                    </p>
                </div>
            )}
        </>
    );
}

Invitations.layout = {
    title: 'Team invitations',
    description: 'Accept or decline the teams you have been invited to join',
};
