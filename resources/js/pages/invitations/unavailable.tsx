import { Head, Link } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

type Props = {
    reason: 'accepted' | 'expired';
    teamName: string;
};

export default function InvitationUnavailable({ reason, teamName }: Props) {
    return (
        <>
            <Head title="Invitation unavailable" />

            <div data-test="invitation-unavailable" className="space-y-4">
                <p className="text-sm text-muted-foreground">
                    {reason === 'accepted'
                        ? `This invitation to ${teamName} has already been used. If that was you, sign in instead.`
                        : `This invitation to ${teamName} has expired. Invitations last three days — ask whoever invited you to send a new one.`}
                </p>

                <Button asChild className="w-full">
                    <Link href={login()}>Go to sign in</Link>
                </Button>
            </div>
        </>
    );
}

InvitationUnavailable.layout = {
    title: 'Invitation unavailable',
    description: 'This invitation can no longer be used',
};
