import { Form } from '@inertiajs/react';
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
 * Disconnecting clears the connection from this workspace and leaves the
 * number registered on the Cloud API (docs/modules/m0-onboarding.md §7).
 *
 * There is no deregister option, so there is nothing here to branch on: a
 * Coexistence number (`is_on_biz_app`) disconnects like any other, and the
 * copy says what stays behind rather than warning about a call we never make.
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
                <div className="space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">
                        Disconnecting removes {number.displayPhoneNumber} and
                        this workspace&rsquo;s stored WhatsApp credentials from
                        Luminous, and stops Meta sending us this
                        account&rsquo;s notifications. You will need to run
                        Embedded Signup again to reconnect.
                    </p>
                    <p className="text-sm">
                        The number itself is left alone: it stays registered on
                        the WhatsApp Cloud API and keeps working
                        {number.isOnBizApp
                            ? ', including in the WhatsApp Business app on the handset'
                            : ''}
                        . Conversation history is kept.
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
                                        This clears the WhatsApp Business
                                        Account, number and stored credentials
                                        from this workspace and unsubscribes us
                                        from its notifications.{' '}
                                        {number.displayPhoneNumber} (
                                        {number.verifiedName}) stays registered
                                        on the WhatsApp Cloud API. Conversation
                                        history is kept.
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
