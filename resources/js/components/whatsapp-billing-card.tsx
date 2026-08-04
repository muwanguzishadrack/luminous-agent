import { CircleAlert, CreditCard, ExternalLink } from 'lucide-react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

type Props = {
    paymentReady: boolean;
    billingUrl: string;
};

/**
 * Tech Provider model: the client attaches their own payment method and Meta
 * bills them directly. We hold no credit line and cannot see their spend, so
 * this panel links out and never quotes a figure
 * (docs/reference/whatsapp-cloud-api.md §5 E).
 */
export default function WhatsAppBillingCard({
    paymentReady,
    billingUrl,
}: Props) {
    return (
        <section className="space-y-4" aria-label="Billing and payments">
            <Heading
                variant="small"
                title="Billing & payments"
                description="Conversation charges are billed by Meta, directly to your business."
            />

            {!paymentReady ? (
                <Alert
                    variant="destructive"
                    data-test="whatsapp-payment-warning"
                >
                    <CircleAlert className="size-4" />
                    <AlertTitle>No payment method attached</AlertTitle>
                    <AlertDescription>
                        <p>
                            Meta has no payment method on file for this WhatsApp
                            Business Account. Sends will fail with error 131042
                            until one is attached in Meta&rsquo;s billing
                            centre.
                        </p>
                    </AlertDescription>
                </Alert>
            ) : null}

            <div className="space-y-3 rounded-lg border p-4">
                <div className="flex items-start gap-3">
                    <CreditCard className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <div className="space-y-1 text-sm">
                        <p className="font-medium">
                            Meta bills you directly for conversations
                        </p>
                        <p className="text-muted-foreground">
                            Luminous is your WhatsApp technology provider, not
                            your reseller. Your payment method sits with Meta,
                            and your conversation charges and invoices are only
                            visible in Meta&rsquo;s billing centre &mdash; we
                            cannot see what you spend.
                        </p>
                    </div>
                </div>

                <Button asChild variant="outline" size="sm">
                    <a
                        href={billingUrl}
                        target="_blank"
                        rel="noreferrer noopener"
                        data-test="whatsapp-billing-link"
                    >
                        Open Meta billing centre
                        <ExternalLink className="size-3.5" />
                    </a>
                </Button>
            </div>
        </section>
    );
}
