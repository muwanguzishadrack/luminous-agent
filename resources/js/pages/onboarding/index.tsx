import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    Circle,
    Building2,
    CircleAlert,
    MessagesSquare,
    KeyRound,
    RotateCcw,
    Smartphone,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import {
    events as onboardingEvents,
    exchange as onboardingExchange,
    index as onboardingIndex,
    resume as onboardingResume,
    start as onboardingStart,
} from '@/routes/onboarding';
import { show as whatsappShow } from '@/routes/whatsapp';

const COEXISTENCE_FEATURE = 'whatsapp_business_app_onboarding';

const SDK_URL = 'https://connect.facebook.net/en_US/sdk.js';

/**
 * onboarding_sessions.status progression (app/Actions/Onboarding/OnboardingStatus.php):
 * started → finished → exchanged → registered → syncing → complete | failed.
 */
const STATUS_RANK: Record<string, number> = {
    started: 0,
    finished: 1,
    exchanged: 2,
    registered: 3,
    syncing: 4,
    complete: 5,
};

/**
 * The seven server chain steps (docs/modules/m0-onboarding.md §1), with the
 * status rank at which each is known to have completed.
 */
const CHAIN_STEPS = [
    {
        name: 'exchange_signup_code',
        title: 'Exchange the signup code',
        doneAt: 2,
    },
    {
        name: 'register_phone_number',
        title: 'Register the phone number',
        doneAt: 3,
    },
    { name: 'subscribe_waba_webhooks', title: 'Subscribe webhooks', doneAt: 4 },
    {
        name: 'sync_waba_assets',
        title: 'Sync account and number details',
        doneAt: 5,
    },
    { name: 'sync_templates', title: 'Import message templates', doneAt: 5 },
    {
        name: 'check_payment_readiness',
        title: 'Check payment readiness',
        doneAt: 5,
    },
    { name: 'complete_onboarding', title: 'Activate the team', doneAt: 5 },
] as const;

const STALLED_STATUSES = ['finished', 'exchanged', 'registered', 'syncing'];

type OnboardingFailure = {
    step: string;
    at?: string;
    error: Record<string, unknown>;
};

type OnboardingSessionProp = {
    id: string;
    status: string;
    featureType: string | null;
    failure: OnboardingFailure | null;
    nonce: string | null;
};

type ChainState = {
    id: string;
    status: string;
    failure: OnboardingFailure | null;
};

type EmbeddedSignupEvent = {
    type?: string;
    event?: string;
    data?: {
        waba_id?: string | number;
        phone_number_id?: string | number;
        current_step?: string;
        [key: string]: unknown;
    };
};

type Props = {
    connectedWabaId: string | null;
    appId: string;
    configId: string;
    graphVersion: string;
    session: OnboardingSessionProp | null;
};

type StepState = 'done' | 'active' | 'failed' | 'pending';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function postJson<T>(
    url: string,
    payload: Record<string, unknown>,
): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
            message?: string;
        } | null;

        throw new Error(
            body?.message ?? `The request failed (HTTP ${response.status}).`,
        );
    }

    return (await response.json()) as T;
}

function stepStatesFor(
    flow: OnboardingSessionProp | null,
    busy: boolean,
): StepState[] {
    if (flow === null || flow.status === 'started') {
        return CHAIN_STEPS.map(() => 'pending');
    }

    if (flow.status === 'failed' && flow.failure) {
        const failedIndex = CHAIN_STEPS.findIndex(
            (step) => step.name === flow.failure?.step,
        );

        return CHAIN_STEPS.map((step, index) => {
            if (failedIndex === -1) {
                return 'pending';
            }

            if (index < failedIndex) {
                return 'done';
            }

            return index === failedIndex
                ? busy
                    ? 'active'
                    : 'failed'
                : 'pending';
        });
    }

    const rank = STATUS_RANK[flow.status] ?? 0;
    let activeAssigned = false;

    return CHAIN_STEPS.map((step) => {
        if (rank >= step.doneAt) {
            return 'done';
        }

        if (busy && !activeAssigned) {
            activeAssigned = true;

            return 'active';
        }

        return 'pending';
    });
}

function StepIcon({ state }: { state: StepState }) {
    if (state === 'done') {
        return <CheckCircle2 className="size-4 text-emerald-600" />;
    }

    if (state === 'active') {
        return <Spinner className="size-4 text-muted-foreground" />;
    }

    if (state === 'failed') {
        return <CircleAlert className="size-4 text-destructive" />;
    }

    return <Circle className="size-4 text-muted-foreground/40" />;
}

export default function OnboardingIndex({
    connectedWabaId,
    appId,
    configId,
    graphVersion,
    session,
}: Props) {
    const page = usePage();
    const team = page.props.team;
    const slug = team?.slug;

    const [sdkState, setSdkState] = useState<'loading' | 'ready' | 'failed'>(
        () =>
            typeof window !== 'undefined' && window.FB ? 'ready' : 'loading',
    );
    const [flow, setFlow] = useState<OnboardingSessionProp | null>(session);
    const [lastSession, setLastSession] =
        useState<OnboardingSessionProp | null>(session);
    const [launching, setLaunching] = useState(false);
    const [exchanging, setExchanging] = useState(false);
    const [resuming, setResuming] = useState(false);
    const [clientError, setClientError] = useState<string | null>(null);

    const nonceRef = useRef<string | null>(session?.nonce ?? null);
    const finishRef = useRef<{
        wabaId?: string;
        phoneNumberId?: string;
        code?: string;
        featureType?: string;
    }>({});
    const exchangingRef = useRef(false);

    // Keep local flow state in sync after Inertia partial reloads —
    // adjusted during render rather than in an effect.
    if (session !== lastSession) {
        setLastSession(session);

        if (session) {
            setFlow(session);
        }
    }

    // Load the Facebook JS SDK, with a graceful error state when it is
    // blocked (ad blockers are the usual culprit).
    useEffect(() => {
        if (window.FB) {
            window.FB.init({
                appId,
                autoLogAppEvents: true,
                xfbml: false,
                version: graphVersion,
            });

            return;
        }

        window.fbAsyncInit = () => {
            window.FB?.init({
                appId,
                autoLogAppEvents: true,
                xfbml: false,
                version: graphVersion,
            });
            setSdkState('ready');
        };

        const script = document.createElement('script');
        script.src = SDK_URL;
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.onerror = () => setSdkState('failed');
        document.head.appendChild(script);
    }, [appId, graphVersion]);

    const maybeExchange = useCallback(async () => {
        const { wabaId, phoneNumberId, code, featureType } = finishRef.current;
        const nonce = nonceRef.current;

        if (
            !wabaId ||
            !phoneNumberId ||
            !code ||
            !nonce ||
            exchangingRef.current
        ) {
            return;
        }

        exchangingRef.current = true;
        setExchanging(true);

        try {
            const state = await postJson<ChainState>(onboardingExchange.url(), {
                nonce,
                code,
                waba_id: wabaId,
                phone_number_id: phoneNumberId,
                ...(featureType === COEXISTENCE_FEATURE
                    ? { feature_type: featureType }
                    : {}),
            });

            finishRef.current = {};
            setFlow((previous) => ({
                id: state.id,
                status: state.status,
                failure: state.failure,
                featureType: featureType ?? previous?.featureType ?? null,
                nonce: null,
            }));
            router.reload({ only: ['session'] });
        } catch (error) {
            setClientError(
                error instanceof Error
                    ? error.message
                    : 'Exchanging the signup code failed.',
            );
        } finally {
            exchangingRef.current = false;
            setExchanging(false);
            setLaunching(false);
        }
    }, []);

    // Capture every WA_EMBEDDED_SIGNUP session event from the ES window
    // (docs/modules/m0-onboarding.md §1).
    useEffect(() => {
        const onMessage = (event: MessageEvent) => {
            let host = '';

            try {
                host = new URL(event.origin).hostname;
            } catch {
                return;
            }

            if (host !== 'facebook.com' && !host.endsWith('.facebook.com')) {
                return;
            }

            if (typeof event.data !== 'string') {
                return;
            }

            let payload: EmbeddedSignupEvent;

            try {
                payload = JSON.parse(event.data) as EmbeddedSignupEvent;
            } catch {
                return;
            }

            if (payload.type !== 'WA_EMBEDDED_SIGNUP') {
                return;
            }

            const nonce = nonceRef.current;

            if (nonce) {
                void postJson(onboardingEvents.url(), {
                    nonce,
                    event: payload,
                }).catch(() => undefined);
            }

            if (
                payload.event === 'FINISH' ||
                payload.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'
            ) {
                if (payload.data?.waba_id !== undefined) {
                    finishRef.current.wabaId = String(payload.data.waba_id);
                }

                if (payload.data?.phone_number_id !== undefined) {
                    finishRef.current.phoneNumberId = String(
                        payload.data.phone_number_id,
                    );
                }

                if (
                    payload.event === 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING'
                ) {
                    finishRef.current.featureType = COEXISTENCE_FEATURE;
                }

                void maybeExchange();
            }

            if (payload.event === 'CANCEL') {
                setLaunching(false);
            }
        };

        window.addEventListener('message', onMessage);

        return () => window.removeEventListener('message', onMessage);
    }, [maybeExchange]);

    const launch = async (
        featureType: string,
        existingNonce?: string | null,
    ) => {
        if (!window.FB || sdkState !== 'ready' || launching || exchanging) {
            return;
        }

        setClientError(null);
        setLaunching(true);

        try {
            if (existingNonce) {
                nonceRef.current = existingNonce;
            } else {
                const created = await postJson<{
                    id: string;
                    nonce: string;
                    status: string;
                }>(onboardingStart.url(), {});

                nonceRef.current = created.nonce;
                setFlow({
                    id: created.id,
                    status: created.status,
                    featureType: featureType || null,
                    failure: null,
                    nonce: created.nonce,
                });
            }

            finishRef.current = featureType ? { featureType } : {};

            window.FB.login(
                (response) => {
                    const code = response.authResponse?.code;

                    if (code) {
                        finishRef.current.code = code;
                        void maybeExchange();
                    } else {
                        setLaunching(false);
                    }
                },
                {
                    config_id: configId,
                    response_type: 'code',
                    override_default_response_type: true,
                    extras: {
                        setup: {},
                        featureType,
                        sessionInfoVersion: '3',
                    },
                },
            );
        } catch (error) {
            setClientError(
                error instanceof Error
                    ? error.message
                    : 'Starting the signup session failed.',
            );
            setLaunching(false);
        }
    };

    // 133005 = PIN mismatch, 133008/133009 = too many/too fast PIN guesses.
    // All three mean: we need the number's real two-step-verification PIN.
    const failureCode = Number(
        (flow?.failure?.error as { code?: number } | undefined)?.code ?? 0,
    );
    const needsPin = [133005, 133008, 133009].includes(failureCode);
    const [pin, setPin] = useState('');

    const resumeChain = async (suppliedPin?: string) => {
        if (!flow || resuming || exchanging) {
            return;
        }

        setClientError(null);
        setResuming(true);

        try {
            const state = await postJson<ChainState>(
                onboardingResume.url(flow.id),
                suppliedPin ? { pin: suppliedPin } : {},
            );

            setFlow((previous) =>
                previous
                    ? {
                          ...previous,
                          status: state.status,
                          failure: state.failure,
                      }
                    : previous,
            );
            router.reload({ only: ['session'] });
        } catch (error) {
            setClientError(
                error instanceof Error
                    ? error.message
                    : 'Resuming the onboarding failed.',
            );
        } finally {
            setResuming(false);
        }
    };

    const busy = exchanging || resuming;
    const steps = stepStatesFor(flow, busy);
    const isComplete = flow?.status === 'complete';
    const isFailed = flow?.status === 'failed';
    const isStalled =
        flow !== null && STALLED_STATUSES.includes(flow.status) && !busy;
    const isAbandoned =
        flow?.status === 'started' &&
        flow.nonce !== null &&
        !launching &&
        !exchanging;
    const launchDisabled = sdkState !== 'ready' || launching || exchanging;

    return (
        <>
            <Head title="Connect WhatsApp" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Heading
                    title="Connect WhatsApp"
                    description="Link a WhatsApp Business number through Meta's Embedded Signup — live in your inbox in under ten minutes."
                />

                {/* There is exactly one team this can land in (D-020) —
                    naming it is reassurance, not a choice. */}
                <Alert>
                    <Building2 className="size-4" />
                    <AlertTitle>
                        Connecting to {team?.name || 'your team'}
                    </AlertTitle>
                    <AlertDescription>
                        The number you connect belongs to this team. A team
                        holds one WhatsApp Business Account and one number.
                    </AlertDescription>
                </Alert>

                {sdkState === 'failed' && (
                    <Alert variant="destructive">
                        <CircleAlert className="size-4" />
                        <AlertTitle>
                            The Facebook SDK could not be loaded
                        </AlertTitle>
                        <AlertDescription>
                            Signup opens in a Facebook window, which requires
                            their SDK. An ad blocker or privacy extension is
                            usually the cause — disable it for this page and
                            reload.
                        </AlertDescription>
                    </Alert>
                )}

                {clientError && (
                    <Alert variant="destructive">
                        <CircleAlert className="size-4" />
                        <AlertTitle>Something went wrong</AlertTitle>
                        <AlertDescription>{clientError}</AlertDescription>
                    </Alert>
                )}

                {connectedWabaId !== null && !isComplete && (
                    <Alert>
                        <CircleAlert className="size-4" />
                        <AlertTitle>
                            This team is already connected to WhatsApp
                        </AlertTitle>
                        <AlertDescription>
                            WhatsApp Business Account {connectedWabaId} is
                            already connected. A team holds one account and one
                            number — disconnect the current one first, or use a
                            separate login for another business.
                        </AlertDescription>
                    </Alert>
                )}

                {isComplete || connectedWabaId !== null ? (
                    <Card>
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <CheckCircle2 className="size-5 text-emerald-600" />
                                <CardTitle>WhatsApp is connected</CardTitle>
                            </div>
                            <CardDescription>
                                Your number is registered, webhooks are
                                subscribed, and your templates are imported. You
                                are ready to message.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-wrap gap-2">
                            <Button asChild>
                                <Link href={whatsappShow()}>
                                    View your connection
                                </Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={slug ? dashboard(slug) : '/'}>
                                    Go to the dashboard
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <>
                        {isAbandoned && (
                            <Alert>
                                <RotateCcw className="size-4" />
                                <AlertTitle>
                                    You have an unfinished signup
                                </AlertTitle>
                                <AlertDescription>
                                    <p>
                                        A previous signup was closed before it
                                        finished. Pick it up where you left off,
                                        or start a fresh connection below.
                                    </p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-2"
                                        disabled={launchDisabled}
                                        onClick={() =>
                                            void launch(
                                                flow.featureType ?? '',
                                                flow.nonce,
                                            )
                                        }
                                    >
                                        Resume signup
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="grid items-start gap-4 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Get started</CardTitle>
                                    <CardDescription>
                                        Signup happens in a Facebook window.
                                        Have your business details and the phone
                                        number you want to connect at hand.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3">
                                    <Button
                                        className="justify-start"
                                        disabled={launchDisabled}
                                        onClick={() => void launch('')}
                                    >
                                        {launching || exchanging ? (
                                            <Spinner />
                                        ) : (
                                            <MessagesSquare className="size-4" />
                                        )}
                                        Connect WhatsApp
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="justify-start"
                                        disabled={launchDisabled}
                                        onClick={() =>
                                            void launch(COEXISTENCE_FEATURE)
                                        }
                                    >
                                        <Smartphone className="size-4" />
                                        Connect existing WhatsApp Business app
                                        number
                                    </Button>
                                    <p className="text-sm text-muted-foreground">
                                        Already using the WhatsApp Business app
                                        on your phone? The second option keeps
                                        the app working on your handset while
                                        your team uses this CRM on the same
                                        number. Requires WhatsApp Business app
                                        2.24.17 or newer.
                                    </p>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Setup progress</CardTitle>
                                    <CardDescription>
                                        These steps run automatically once the
                                        Facebook window finishes.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-3">
                                    <ul className="flex flex-col gap-2">
                                        {CHAIN_STEPS.map((step, index) => (
                                            <li
                                                key={step.name}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <StepIcon
                                                    state={steps[index]}
                                                />
                                                <span
                                                    className={
                                                        steps[index] ===
                                                        'pending'
                                                            ? 'text-muted-foreground'
                                                            : ''
                                                    }
                                                >
                                                    {step.title}
                                                </span>
                                                {steps[index] === 'failed' && (
                                                    <Badge variant="destructive">
                                                        Failed
                                                    </Badge>
                                                )}
                                            </li>
                                        ))}
                                    </ul>

                                    {isFailed && needsPin && !busy && (
                                        <Alert>
                                            <KeyRound className="size-4" />
                                            <AlertTitle>
                                                This number already has two-step
                                                verification
                                            </AlertTitle>
                                            <AlertDescription>
                                                <p className="mb-2 text-sm">
                                                    Meta needs the
                                                    number&rsquo;s existing
                                                    6-digit PIN to register it.
                                                    If you don&rsquo;t know it,
                                                    change it in WhatsApp
                                                    Manager under the
                                                    number&rsquo;s Settings
                                                    &rsquo;Two-step
                                                    verification&rsquo;, then
                                                    enter the new PIN here.
                                                </p>
                                                <div className="flex items-center gap-2">
                                                    <Input
                                                        inputMode="numeric"
                                                        autoComplete="one-time-code"
                                                        maxLength={6}
                                                        pattern="[0-9]{6}"
                                                        placeholder="6-digit PIN"
                                                        className="w-40"
                                                        value={pin}
                                                        onChange={(event) =>
                                                            setPin(
                                                                event.target.value
                                                                    .replace(
                                                                        /\D/g,
                                                                        '',
                                                                    )
                                                                    .slice(
                                                                        0,
                                                                        6,
                                                                    ),
                                                            )
                                                        }
                                                    />
                                                    <Button
                                                        size="sm"
                                                        disabled={
                                                            resuming ||
                                                            pin.length !== 6
                                                        }
                                                        onClick={() =>
                                                            void resumeChain(
                                                                pin,
                                                            )
                                                        }
                                                    >
                                                        {resuming ? (
                                                            <Spinner />
                                                        ) : (
                                                            <RotateCcw className="size-4" />
                                                        )}
                                                        Register with this PIN
                                                    </Button>
                                                </div>
                                            </AlertDescription>
                                        </Alert>
                                    )}

                                    {isFailed && flow?.failure && !busy && (
                                        <Alert variant="destructive">
                                            <CircleAlert className="size-4" />
                                            <AlertTitle>
                                                Failed at{' '}
                                                {flow.failure.step.replaceAll(
                                                    '_',
                                                    ' ',
                                                )}
                                            </AlertTitle>
                                            <AlertDescription>
                                                <pre className="max-h-40 overflow-auto rounded bg-muted/50 p-2 text-xs whitespace-pre-wrap">
                                                    {JSON.stringify(
                                                        flow.failure.error,
                                                        null,
                                                        2,
                                                    )}
                                                </pre>
                                                {!needsPin && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="mt-2"
                                                        disabled={resuming}
                                                        onClick={() =>
                                                            void resumeChain()
                                                        }
                                                    >
                                                        {resuming ? (
                                                            <Spinner />
                                                        ) : (
                                                            <RotateCcw className="size-4" />
                                                        )}
                                                        Resume from failed step
                                                    </Button>
                                                )}
                                            </AlertDescription>
                                        </Alert>
                                    )}

                                    {isStalled && !isFailed && (
                                        <div className="flex flex-col gap-2">
                                            <p className="text-sm text-muted-foreground">
                                                Setup paused before finishing —
                                                it is resumable from the last
                                                completed step.
                                            </p>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                className="w-fit"
                                                disabled={resuming}
                                                onClick={() =>
                                                    void resumeChain()
                                                }
                                            >
                                                {resuming ? (
                                                    <Spinner />
                                                ) : (
                                                    <RotateCcw className="size-4" />
                                                )}
                                                Continue setup
                                            </Button>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </>
                )}
            </div>
        </>
    );
}

OnboardingIndex.layout = {
    breadcrumbs: [
        {
            title: 'Connect WhatsApp',
            href: onboardingIndex(),
        },
    ],
};
