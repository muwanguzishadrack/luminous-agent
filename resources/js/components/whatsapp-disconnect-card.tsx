import { Form } from '@inertiajs/react';
import { Smartphone } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
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
import { disconnect } from '@/routes/whatsapp';
import type { WhatsAppPhoneNumber } from '@/types';

type Props = {
    number: WhatsAppPhoneNumber;
    canManage: boolean;
};

/**
 * Meta does not permit `POST /{phone-number-id}/deregister` for a Coexistence
 * number, so the panel branches on `isOnBizApp` rather than offering a button
 * that would always fail (docs/reference/whatsapp-cloud-api.md §5).
 */
export default function WhatsAppDisconnectCard({ number, canManage }: Props) {
    const [open, setOpen] = useState(false);

    return (
        <section className="space-y-4" aria-label="Disconnect WhatsApp">
            <Heading
                variant="small"
                title="Disconnect WhatsApp"
                description="Stop sending and receiving on this number from Luminous."
            />

            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                {number.isOnBizApp ? (
                    <div
                        className="space-y-3"
                        data-test="whatsapp-coexistence-disconnect"
                    >
                        <div className="flex items-start gap-3 text-red-600 dark:text-red-100">
                            <Smartphone className="mt-0.5 size-4 shrink-0" />
                            <div className="space-y-1">
                                <p className="font-medium">
                                    Disconnect from the WhatsApp Business app
                                </p>
                                <p className="text-sm">
                                    {number.displayPhoneNumber} is a Coexistence
                                    number &mdash; it is still linked to the
                                    WhatsApp Business app on a phone. Meta does
                                    not allow it to be disconnected from here.
                                </p>
                            </div>
                        </div>

                        <ol className="ml-7 list-decimal space-y-1 text-sm text-red-600 dark:text-red-100">
                            <li>
                                Open the WhatsApp Business app on the phone
                                holding {number.displayPhoneNumber}.
                            </li>
                            <li>
                                Go to <strong>Settings</strong> &rarr;{' '}
                                <strong>Account</strong> &rarr;{' '}
                                <strong>Business Platform</strong>.
                            </li>
                            <li>
                                Tap <strong>Disconnect</strong>.
                            </li>
                        </ol>

                        <p className="ml-7 text-sm text-red-600 dark:text-red-100">
                            Meta then notifies Luminous and the connection is
                            cleared here automatically.
                        </p>
                    </div>
                ) : (
                    <>
                        <div className="space-y-0.5 text-red-600 dark:text-red-100">
                            <p className="font-medium">Warning</p>
                            <p className="text-sm">
                                Disconnecting deregisters{' '}
                                {number.displayPhoneNumber} from the Cloud API
                                and removes this workspace&rsquo;s WhatsApp
                                access. You will need to run Embedded Signup
                                again to reconnect.
                            </p>
                            <p className="text-sm">
                                Meta will refuse the request if this number sent
                                paid messages in the last 30 days.
                            </p>
                        </div>

                        {canManage ? (
                            <Button
                                variant="destructive"
                                data-test="whatsapp-disconnect-button"
                                onClick={() => setOpen(true)}
                            >
                                Disconnect WhatsApp
                            </Button>
                        ) : null}
                    </>
                )}
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <Form
                        key={String(open)}
                        {...disconnect.form()}
                        className="space-y-6"
                        onSuccess={() => setOpen(false)}
                    >
                        {({ processing }) => (
                            <>
                                <DialogHeader>
                                    <DialogTitle>
                                        Disconnect {number.displayPhoneNumber}?
                                    </DialogTitle>
                                    <DialogDescription>
                                        This deregisters{' '}
                                        {number.displayPhoneNumber} (
                                        {number.verifiedName}) from the WhatsApp
                                        Cloud API and clears this
                                        workspace&rsquo;s WhatsApp Business
                                        Account, number and stored credentials.
                                        Conversation history is kept.
                                    </DialogDescription>
                                </DialogHeader>

                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button variant="secondary">
                                            Cancel
                                        </Button>
                                    </DialogClose>

                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        data-test="whatsapp-disconnect-confirm"
                                        disabled={processing}
                                    >
                                        Disconnect
                                    </Button>
                                </DialogFooter>
                            </>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </section>
    );
}
