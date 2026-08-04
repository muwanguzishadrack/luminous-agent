import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/invitations/join';

type Props = {
    invitation: {
        code: string;
        email: string;
        teamName: string;
        inviterName: string;
        roleLabel: string;
    };
};

export default function JoinTeam({ invitation }: Props) {
    return (
        <>
            <Head title={`Join ${invitation.teamName}`} />

            <div
                data-test="join-invitation-summary"
                className="rounded-lg border bg-muted/40 p-4 text-sm"
            >
                <p>
                    <span className="font-medium">{invitation.inviterName}</span>{' '}
                    invited you to join{' '}
                    <span className="font-medium">{invitation.teamName}</span> as{' '}
                    {invitation.roleLabel}.
                </p>
            </div>

            <Form
                {...store.form(invitation)}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="mt-6 flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            {/* The address is the invitation's and is not a
                                field — a writable one would let an invitation
                                be redirected to a different account. */}
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    value={invitation.email}
                                    readOnly
                                    disabled
                                    data-test="join-invitation-email"
                                />
                                <p className="text-xs text-muted-foreground">
                                    Your account uses the address this
                                    invitation was sent to.
                                </p>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    name="name"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="name"
                                    placeholder="Full name"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="new-password"
                                    placeholder="Password"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    Confirm password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    required
                                    tabIndex={3}
                                    autoComplete="new-password"
                                    placeholder="Confirm password"
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                tabIndex={4}
                                data-test="join-invitation-submit"
                            >
                                {processing && <Spinner />}
                                Join {invitation.teamName}
                            </Button>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

JoinTeam.layout = {
    title: 'Set up your account',
    description: 'Choose a password to finish joining the team',
};
