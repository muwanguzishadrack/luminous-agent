<?php

namespace Database\Seeders;

use App\Enums\CampaignStatus;
use App\Enums\ConversationState;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\TenantRole;
use App\Models\Campaign;
use App\Models\PhoneNumber;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WabaAccount;
use App\Support\Facades\Tenancy;
use Carbon\CarbonInterface;
use Database\Factories\CampaignFactory;
use Database\Factories\CannedReplyFactory;
use Database\Factories\ContactFactory;
use Database\Factories\CtwaReferralFactory;
use Database\Factories\IotecWalletFactory;
use Database\Factories\LabelFactory;
use Database\Factories\MetaCredentialFactory;
use Database\Factories\OrderFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\PhoneNumberFactory;
use Database\Factories\TemplateFactory;
use Database\Factories\TemplateGroupFactory;
use Database\Factories\WabaAccountFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 0 deliverable 0.7 (docs/90-roadmap.md): one realistic demo tenant —
 * 5 users, 1 WABA, 2 numbers (one Coexistence), 2,000 contacts with a
 * consistent consent ledger, 30 conversations across all four ownership
 * states, 12 templates in every Meta status, 3 campaigns, 40 orders with
 * payments in every ioTec status, CTWA referrals, labels and canned replies.
 *
 * Runs as the runtime role: Tenancy::initialize() is called right after the
 * tenant exists so Postgres RLS admits every insert. Bulk inserts bypass
 * Eloquent, so those rows carry tenant_id explicitly.
 */
class DemoTenantSeeder extends Seeder
{
    private const CONTACT_COUNT = 2000;

    private const CONVERSATION_COUNT = 30;

    /**
     * The demo tenant id, set once the tenant exists.
     */
    private string $tenantId = '';

    /**
     * Users keyed by role value.
     *
     * @var array<string, User>
     */
    private array $users = [];

    /**
     * Contact rows as inserted: [id, wa_id, profile_name, ...].
     *
     * @var array<int, array<string, mixed>>
     */
    private array $contacts = [];

    /**
     * Marketing consent outcome per contact id: granted|revoked|none.
     *
     * @var array<string, string>
     */
    private array $marketingConsent = [];

    /**
     * Seed the demo tenant.
     */
    public function run(): void
    {
        $started = microtime(true);

        DB::transaction(function () {
            $tenant = $this->seedTenantAndUsers();

            // Every tenant-scoped insert below requires context — RLS enforces it.
            Tenancy::initialize($tenant);
            $this->tenantId = $tenant->id;

            [$waba, $standardNumber, $coexistenceNumber] = $this->seedMetaAssets();
            $this->seedContacts();
            $this->seedConsents();
            $templates = $this->seedTemplates($waba);
            $conversations = $this->seedConversations($standardNumber, $coexistenceNumber, $templates);
            $this->seedCtwaReferrals($conversations);
            $this->seedCampaigns($standardNumber, $templates);
            $this->seedCommerce($conversations);
            $this->seedLabels($conversations);
            $this->seedCannedReplies();
        });

        $this->command->info(sprintf('Demo tenant seeded in %.1fs', microtime(true) - $started));
    }

    /**
     * Create the demo owner + 4 role users, the demo tenant and memberships.
     */
    private function seedTenantAndUsers(): Tenant
    {
        // UserFactory::configure() gives each user a personal tenant and
        // initializes tenancy for it — the demo tenant context is
        // (re-)established after this method returns.
        $this->users = [
            TenantRole::Owner->value => User::factory()->create([
                'name' => 'Demo Owner',
                'email' => 'demo@luminouscrm.test',
            ]),
            TenantRole::Admin->value => User::factory()->create([
                'name' => 'Amina Nakato',
                'email' => 'admin@luminouscrm.test',
            ]),
            TenantRole::Supervisor->value => User::factory()->create([
                'name' => 'Brian Okello',
                'email' => 'supervisor@luminouscrm.test',
            ]),
            TenantRole::Agent->value => User::factory()->create([
                'name' => 'Cissy Namuli',
                'email' => 'agent@luminouscrm.test',
            ]),
            TenantRole::Viewer->value => User::factory()->create([
                'name' => 'David Mugisha',
                'email' => 'viewer@luminouscrm.test',
            ]),
        ];

        $tenant = Tenant::factory()->create([
            'name' => 'Luminous Demo Store',
            'slug' => 'demo',
            'is_personal' => false,
            'status' => 'active',
            'plan' => 'growth',
            'country' => 'UG',
            'default_currency' => 'UGX',
            'settings' => ['branding' => ['primary_color' => '#4f46e5']],
        ]);

        foreach ($this->users as $role => $user) {
            $tenant->members()->attach($user->id, [
                'role' => $role,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $user->switchTenant($tenant);
        }

        return $tenant;
    }

    /**
     * One WABA with a vaulted token and two numbers: STANDARD Cloud API and
     * a Coexistence number (is_on_biz_app = true → fixed 20 mps).
     *
     * @return array{0: WabaAccount, 1: PhoneNumber, 2: PhoneNumber}
     */
    private function seedMetaAssets(): array
    {
        $waba = WabaAccountFactory::new()->createOne([
            'name' => 'Luminous Demo Store',
            'portfolio_messaging_limit' => '10000',
        ]);

        MetaCredentialFactory::new()->createOne(['waba_account_id' => $waba->id]);

        $standard = PhoneNumberFactory::new()->createOne([
            'waba_account_id' => $waba->id,
            'display_phone_number' => '+256 700 100 200',
            'verified_name' => 'Luminous Demo Store',
            'throughput_level' => 'STANDARD',
        ]);

        $coexistence = PhoneNumberFactory::new()->coexistence()->createOne([
            'waba_account_id' => $waba->id,
            'display_phone_number' => '+256 772 300 400',
            'verified_name' => 'Luminous Demo Store — Shop Floor',
        ]);

        return [$waba, $standard, $coexistence];
    }

    /**
     * Bulk-insert 2,000 contacts.
     */
    private function seedContacts(): void
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = ContactFactory::new()->count(self::CONTACT_COUNT)->raw();

        foreach ($rows as &$row) {
            $row['id'] = (string) Str::uuid7();
            $row['tenant_id'] = $this->tenantId;
        }
        unset($row);

        $this->insertRows('contacts', $rows);

        $this->contacts = $rows;
    }

    /**
     * Append-only consents plus the materialised consent_states read model,
     * written consistently: the state row always mirrors the latest event
     * per contact + scope.
     */
    private function seedConsents(): void
    {
        $consentRows = [];

        foreach ($this->contacts as $i => $contact) {
            /** @var string $contactId */
            $contactId = $contact['id'];
            $bucket = $i % 20;
            $grantedAt = Carbon::instance(fake()->dateTimeBetween('-90 days', '-10 days'));

            if ($bucket <= 10) {
                // 55% — opted in by texting a keyword.
                $consentRows[] = $this->consentRow($contactId, 'marketing', 'granted', 'inbound_keyword', [
                    'wamid' => $this->wamid(),
                    'keyword' => 'START',
                ], $grantedAt);
                $this->marketingConsent[$contactId] = 'granted';
            } elseif ($bucket <= 13) {
                // 15% — vetted list import, all scopes.
                $consentRows[] = $this->consentRow($contactId, 'all', 'granted', 'import', [
                    'uploader' => 'demo@luminouscrm.test',
                    'file' => 'contacts-import.csv',
                ], $grantedAt);
                $this->marketingConsent[$contactId] = 'granted';
            } elseif ($bucket <= 16) {
                // 15% — granted earlier, then revoked natively in WhatsApp.
                $consentRows[] = $this->consentRow($contactId, 'marketing', 'granted', 'import', [
                    'uploader' => 'demo@luminouscrm.test',
                    'file' => 'contacts-import.csv',
                ], $grantedAt);
                $consentRows[] = $this->consentRow($contactId, 'marketing', 'revoked', 'whatsapp_native', [
                    'event' => 'user_preferences',
                    'value' => 'stop',
                ], $grantedAt->copy()->addDays(fake()->numberBetween(1, 9)));
                $this->marketingConsent[$contactId] = 'revoked';
            } else {
                // 15% — no consent recorded at all.
                $this->marketingConsent[$contactId] = 'none';
            }
        }

        $this->insertRows('consents', $consentRows);

        // Materialise consent_states from the winning (latest) event per
        // contact + scope — exactly what the listener would have produced.
        $winners = DB::select(
            'SELECT DISTINCT ON (contact_id, scope) id, contact_id, scope, state, source, occurred_at
             FROM consents
             ORDER BY contact_id, scope, occurred_at DESC, id DESC'
        );

        $stateRows = [];

        foreach ($winners as $winner) {
            $stateRows[] = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenantId,
                'contact_id' => $winner->contact_id,
                'scope' => $winner->scope,
                'state' => $winner->state,
                'source' => $winner->source,
                'occurred_at' => $winner->occurred_at,
                'consent_id' => $winner->id,
            ];
        }

        $this->insertRows('consent_states', $stateRows);
    }

    /**
     * 12 templates spanning every Meta status across all three categories,
     * including a two-language group.
     *
     * @return array<string, Template>
     */
    private function seedTemplates(WabaAccount $waba): array
    {
        $factory = TemplateFactory::new()->state(['waba_account_id' => $waba->id]);

        $group = TemplateGroupFactory::new()->createOne([
            'key' => 'order_confirmation',
            'name' => 'Order Confirmation',
        ]);

        $orderConfirmationComponents = [
            [
                'type' => 'BODY',
                'text' => 'Hi {{1}}, your order {{2}} is confirmed. Total: UGX {{3}}. We will message you when it ships.',
                'example' => ['body_text' => [['Aisha', 'ORD-2026-10041', '86,000']]],
            ],
            ['type' => 'BUTTONS', 'buttons' => [['type' => 'QUICK_REPLY', 'text' => 'View order']]],
        ];

        $templates = [];

        // UTILITY — approved workhorses.
        $templates['order_confirmation'] = $factory->utility()->createOne([
            'name' => 'order_confirmation',
            'language' => 'en',
            'template_group_id' => $group->id,
            'components' => $orderConfirmationComponents,
            'variable_map' => [
                'body' => [
                    '1' => ['field' => 'contact.display_name', 'fallback' => 'there'],
                    '2' => ['field' => 'order.reference', 'fallback' => null],
                    '3' => ['field' => 'order.total', 'fallback' => null],
                ],
            ],
        ]);
        $templates['order_confirmation_lg'] = $factory->utility()->createOne([
            'name' => 'order_confirmation',
            'language' => 'lg',
            'template_group_id' => $group->id,
            'components' => $orderConfirmationComponents,
        ]);
        $templates['delivery_update'] = $factory->utility()->createOne(['name' => 'delivery_update']);

        // AUTHENTICATION.
        $templates['otp_login'] = $factory->authentication()->createOne(['name' => 'otp_login']);

        // MARKETING — one per remaining status plus an approved sender.
        $templates['flash_sale'] = $factory->createOne([
            'name' => 'flash_sale_august',
            'components' => [
                [
                    'type' => 'HEADER',
                    'format' => 'IMAGE',
                    'example' => ['header_handle' => ['4::aWc5c2FsZQ==']],
                ],
                [
                    'type' => 'BODY',
                    'text' => 'Hi {{1}}! Our August flash sale is live — up to {{2}}% off storewide until Sunday.',
                    'example' => ['body_text' => [['Aisha', '40']]],
                ],
                ['type' => 'FOOTER', 'text' => 'Reply STOP to opt out'],
                [
                    'type' => 'BUTTONS',
                    'buttons' => [
                        ['type' => 'URL', 'text' => 'Shop now', 'url' => 'https://demo.luminouscrm.test/shop'],
                        ['type' => 'QUICK_REPLY', 'text' => 'Stop promotions'],
                    ],
                ],
            ],
        ]);
        $templates['weekend_promo'] = $factory->createOne(['name' => 'weekend_promo']);
        $templates['restock_teaser'] = $factory->draft()->createOne(['name' => 'restock_teaser']);
        $templates['new_arrivals'] = $factory->pending()->createOne(['name' => 'new_arrivals']);
        $templates['mega_discount'] = $factory->rejected('ABUSIVE_CONTENT')->createOne(['name' => 'mega_discount']);
        $templates['loyalty_bonus'] = $factory->paused()->createOne(['name' => 'loyalty_bonus']);
        $templates['winback_offer'] = $factory->disabled()->createOne(['name' => 'winback_offer']);
        $templates['clearance_blast'] = $factory->inAppeal()->createOne(['name' => 'clearance_blast']);

        return $templates;
    }

    /**
     * 30 conversations across ai/queued/human/closed with coherent threads.
     *
     * @param  array<string, Template>  $templates
     * @return array<int, array<string, mixed>> conversation summaries
     */
    private function seedConversations(PhoneNumber $standard, PhoneNumber $coexistence, array $templates): array
    {
        $states = array_merge(
            array_fill(0, 8, ConversationState::Ai),
            array_fill(0, 6, ConversationState::Queued),
            array_fill(0, 10, ConversationState::Human),
            array_fill(0, 6, ConversationState::Closed),
        );

        $ctwaIndexes = [0, 8, 14, 15, 24];
        $agents = [
            $this->users[TenantRole::Agent->value],
            $this->users[TenantRole::Supervisor->value],
        ];

        $conversationRows = [];
        $messageRows = [];
        $summaries = [];

        for ($i = 0; $i < self::CONVERSATION_COUNT; $i++) {
            $state = $states[$i];
            $contact = $this->contacts[$i];
            $phoneNumber = $i % 2 === 0 ? $standard : $coexistence;
            $conversationId = (string) Str::uuid7();
            $assignee = in_array($state, [ConversationState::Human, ConversationState::Closed], true)
                ? $agents[$i % 2]
                : null;

            $thread = $this->buildThread($conversationId, $state, $assignee, $templates);

            /** @var CarbonInterface $firstAt */
            $firstAt = $thread['first_at'];
            /** @var CarbonInterface $lastInbound */
            $lastInbound = $thread['last_inbound_at'];
            /** @var CarbonInterface $lastAt */
            $lastAt = $thread['last_at'];
            $isCtwa = in_array($i, $ctwaIndexes, true);

            $conversationRows[] = [
                'id' => $conversationId,
                'tenant_id' => $this->tenantId,
                'phone_number_id' => $phoneNumber->id,
                'contact_id' => $contact['id'],
                'state' => $state,
                'owner_app_id' => $state === ConversationState::Ai ? '742011152697621' : null,
                'assigned_user_id' => $assignee?->id,
                'assigned_at' => $assignee !== null ? $thread['first_response_at'] : null,
                'csw_expires_at' => $lastInbound->copy()->addDay(),
                'fep_expires_at' => $isCtwa ? $firstAt->copy()->addHours(72) : null,
                'last_message_at' => $lastAt,
                'last_inbound_at' => $lastInbound,
                'last_outbound_at' => $thread['last_outbound_at'],
                'unread_count' => $thread['unread_count'],
                'first_response_at' => $thread['first_response_at'],
                'resolved_at' => $state === ConversationState::Closed ? $lastAt->copy()->addMinutes(30) : null,
                'snoozed_until' => null,
                'sla_breached_at' => null,
                'ai_handled_count' => in_array($state, [ConversationState::Ai, ConversationState::Queued], true) ? 1 : 0,
                'human_handled_count' => in_array($state, [ConversationState::Human, ConversationState::Closed], true) ? 1 : 0,
            ];

            $messageRows = array_merge($messageRows, $thread['messages']);

            $summaries[] = [
                'id' => $conversationId,
                'contact_id' => $contact['id'],
                'is_ctwa' => $isCtwa,
                'first_at' => $firstAt,
                'first_wamid' => $thread['first_wamid'],
            ];
        }

        $this->insertRows('conversations', $conversationRows);
        $this->insertRows('messages', $messageRows);

        return $summaries;
    }

    /**
     * Build one realistic message thread: 10–40 messages, mixed types,
     * ordered occurred_at, honest status ladders.
     *
     * @param  array<string, Template>  $templates
     * @return array<string, mixed>
     */
    private function buildThread(string $conversationId, ConversationState $state, ?User $assignee, array $templates): array
    {
        $count = fake()->numberBetween(10, 40);

        $cursor = $state === ConversationState::Closed
            ? now()->subDays(fake()->numberBetween(3, 12))
            : now()->subMinutes(fake()->numberBetween(30, 2000));

        // Walk backwards so threads always end in the past, then reverse.
        $offsets = [];
        for ($i = 0; $i < $count; $i++) {
            $offsets[] = fake()->numberBetween(40, 2700);
        }
        $cursor = $cursor->copy()->subSeconds(array_sum($offsets));

        $messages = [];
        $firstAt = null;
        $firstWamid = null;
        $lastInbound = null;
        $lastOutbound = null;
        $firstResponse = null;

        for ($i = 0; $i < $count; $i++) {
            $cursor = $cursor->copy()->addSeconds($offsets[$i]);
            $occurredAt = $cursor->copy();

            // First message is always the customer opening the thread; queued
            // and ai threads also end on an unanswered inbound.
            $forcedInbound = $i === 0
                || (in_array($state, [ConversationState::Queued, ConversationState::Ai], true) && $i >= $count - 2);

            $inbound = $forcedInbound || fake()->boolean(55);
            $wamid = $this->wamid();

            $row = [
                'id' => (string) Str::uuid7(),
                'tenant_id' => $this->tenantId,
                'conversation_id' => $conversationId,
                'wamid' => $wamid,
                'media_id' => null,
                'replied_to_wamid' => null,
                'reaction_to_wamid' => null,
                'sent_by_user_id' => null,
                'campaign_id' => null,
                'template_id' => null,
                'error_code' => null,
                'error_detail' => null,
                'pricing_category' => null,
                'billable' => null,
                'cost_minor' => null,
                'token_count' => null,
                'sent_at' => null,
                'delivered_at' => null,
                'read_at' => null,
                'failed_at' => null,
                'occurred_at' => $occurredAt,
            ];

            if ($inbound) {
                $row = array_replace($row, $this->inboundMessageAttributes());
                $lastInbound = $occurredAt;
            } else {
                $row = array_replace($row, $this->outboundMessageAttributes($state, $assignee, $templates, $occurredAt));
                $lastOutbound = $occurredAt;
                $firstResponse ??= $occurredAt;
            }

            $firstAt ??= $occurredAt;
            $firstWamid ??= $wamid;
            $messages[] = $row;
        }

        // Unread = trailing inbound messages an agent has not opened yet.
        $unread = 0;
        if ($state === ConversationState::Queued) {
            for ($i = count($messages) - 1; $i >= 0; $i--) {
                if ($messages[$i]['direction'] !== 'inbound') {
                    break;
                }
                $messages[$i]['status'] = 'delivered';
                $unread++;
            }
        }

        return [
            'messages' => $messages,
            'first_at' => $firstAt,
            'first_wamid' => $firstWamid,
            'last_at' => $cursor,
            'last_inbound_at' => $lastInbound ?? $firstAt,
            'last_outbound_at' => $lastOutbound,
            'first_response_at' => $firstResponse,
            'unread_count' => $unread,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inboundMessageAttributes(): array
    {
        $roll = fake()->numberBetween(1, 100);

        if ($roll <= 70) {
            $body = fake()->randomElement([
                'Hi, do you deliver to Ntinda?',
                'How much is the large size?',
                'Is this still available?',
                'I sent the payment, please confirm.',
                'Can I pick it up from the shop tomorrow?',
                'Thanks, that worked!',
                'What are your opening hours?',
                'Do you have it in blue?',
            ]);
            $type = 'text';
            $payload = ['text' => ['body' => $body]];
        } elseif ($roll <= 80) {
            $type = 'image';
            $body = null;
            $payload = ['image' => ['id' => (string) fake()->numberBetween(100_000_000_000_000, 999_999_999_999_999), 'mime_type' => 'image/jpeg', 'sha256' => hash('sha256', (string) $roll)]];
        } elseif ($roll <= 92) {
            $body = fake()->randomElement(['Track my order', 'Talk to an agent', 'View catalogue']);
            $type = 'interactive';
            $payload = ['interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'btn_'.Str::random(6), 'title' => $body]]];
        } else {
            $body = fake()->randomElement(['Yes', 'No, thanks']);
            $type = 'button';
            $payload = ['button' => ['text' => $body, 'payload' => strtoupper(str_replace([' ', ','], ['_', ''], $body))]];
        }

        return [
            'direction' => 'inbound',
            'type' => $type,
            'body' => $body,
            'payload' => $payload,
            'origin' => 'customer',
            'status' => 'read',
        ];
    }

    /**
     * @param  array<string, Template>  $templates
     * @return array<string, mixed>
     */
    private function outboundMessageAttributes(ConversationState $state, ?User $assignee, array $templates, CarbonInterface $occurredAt): array
    {
        $roll = fake()->numberBetween(1, 100);
        $isAi = $state === ConversationState::Ai;

        if ($roll <= 82 || $isAi) {
            $body = fake()->randomElement([
                'Yes, we deliver to Ntinda — delivery is UGX 5,000.',
                'The large size is UGX 65,000. Should I reserve one for you?',
                'It is available! Would you like me to add it to your order?',
                'Payment received, thank you. Your order is being packed.',
                'Sure — pickup is available from 9am to 7pm.',
                'You are welcome! Anything else I can help with?',
            ]);
            $attributes = [
                'type' => 'text',
                'body' => $body,
                'payload' => ['text' => ['body' => $body], 'to' => 'contact'],
                'template_id' => null,
                'pricing_category' => $isAi ? 'meta_business_agent' : 'service',
                'billable' => $isAi,
                'cost_minor' => null,
                'token_count' => $isAi ? fake()->numberBetween(150, 900) : null,
            ];
        } elseif ($roll <= 92) {
            $template = $templates['order_confirmation'];
            $attributes = [
                'type' => 'template',
                'body' => 'Hi there, your order is confirmed. Total: UGX 86,000. We will message you when it ships.',
                'payload' => ['template' => ['name' => $template->name, 'language' => ['code' => 'en']]],
                'template_id' => $template->id,
                'pricing_category' => 'utility',
                'billable' => true,
                'cost_minor' => 120,
                'token_count' => null,
            ];
        } else {
            $attributes = [
                'type' => 'image',
                'body' => null,
                'payload' => ['image' => ['link' => 'https://demo.luminouscrm.test/media/catalogue.jpg', 'caption' => 'Here is the blue one in stock.']],
                'template_id' => null,
                'pricing_category' => 'service',
                'billable' => false,
                'cost_minor' => null,
                'token_count' => null,
            ];
        }

        $statusRoll = fake()->numberBetween(1, 100);
        $status = match (true) {
            $statusRoll <= 55 => 'read',
            $statusRoll <= 85 => 'delivered',
            $statusRoll <= 96 => 'sent',
            default => 'failed',
        };

        $attributes += [
            'direction' => 'outbound',
            'origin' => $isAi ? 'mba' : 'agent',
            'sent_by_user_id' => $isAi ? null : $assignee?->id,
            'status' => $status,
            'sent_at' => $occurredAt,
        ];

        if ($status === 'failed') {
            $attributes['error_code'] = 131026;
            $attributes['error_detail'] = ['title' => 'Message undeliverable'];
            $attributes['failed_at'] = $occurredAt->copy()->addSeconds(5);
        } else {
            if (in_array($status, ['delivered', 'read'], true)) {
                $attributes['delivered_at'] = $occurredAt->copy()->addSeconds(fake()->numberBetween(5, 60));
            }
            if ($status === 'read') {
                $attributes['read_at'] = $occurredAt->copy()->addSeconds(fake()->numberBetween(61, 600));
            }
        }

        return $attributes;
    }

    /**
     * A handful of CTWA referrals tied to conversations, with the 72h free
     * entry point window already stamped on the conversation rows.
     *
     * @param  array<int, array<string, mixed>>  $conversations
     */
    private function seedCtwaReferrals(array $conversations): void
    {
        foreach ($conversations as $conversation) {
            if (! $conversation['is_ctwa']) {
                continue;
            }

            /** @var CarbonInterface $firstAt */
            $firstAt = $conversation['first_at'];

            CtwaReferralFactory::new()->createOne([
                'contact_id' => $conversation['contact_id'],
                'conversation_id' => $conversation['id'],
                'message_wamid' => $conversation['first_wamid'],
                'occurred_at' => $firstAt,
            ]);
        }
    }

    /**
     * 3 campaigns — completed, sending and draft — with recipients in mixed
     * statuses including suppressions with reasons consistent with the
     * consent ledger.
     *
     * @param  array<string, Template>  $templates
     */
    private function seedCampaigns(PhoneNumber $phoneNumber, array $templates): void
    {
        $flashSale = CampaignFactory::new()->completed()->createOne([
            'phone_number_id' => $phoneNumber->id,
            'name' => 'August Flash Sale',
            'template_id' => $templates['flash_sale']->id,
        ]);

        $this->seedFlashSaleRecipients($flashSale);

        $weekend = CampaignFactory::new()->sending()->createOne([
            'phone_number_id' => $phoneNumber->id,
            'name' => 'Weekend Promo Blast',
            'template_id' => $templates['weekend_promo']->id,
        ]);

        $this->seedWeekendRecipients($weekend);

        CampaignFactory::new()->createOne([
            'phone_number_id' => $phoneNumber->id,
            'name' => 'September Restock Teaser',
            'template_id' => $templates['restock_teaser']->id,
            'status' => CampaignStatus::Draft,
        ]);
    }

    /**
     * Completed campaign: full status ladder plus every suppression reason.
     */
    private function seedFlashSaleRecipients(Campaign $campaign): void
    {
        /** @var CarbonInterface $startedAt */
        $startedAt = $campaign->started_at;
        $otherReasons = ['per_user_cap', 'blocked', 'invalid_number', 'missing_variable', 'unsupported_language', 'budget', 'duplicate'];
        $rows = [];
        $stats = [];
        $spent = 0;
        $forced = 0;

        foreach (array_slice($this->contacts, 100, 250) as $i => $contact) {
            /** @var string $contactId */
            $contactId = $contact['id'];
            $queuedAt = $startedAt->copy()->addSeconds($i * 3);

            $row = [
                'tenant_id' => $this->tenantId,
                'campaign_id' => $campaign->id,
                'contact_id' => $contactId,
                'message_id' => null,
                'wamid' => null,
                'status' => 'pending',
                'suppression_reason' => null,
                'error_code' => null,
                'cost_minor' => null,
                'variables' => ['1' => $contact['profile_name'], '2' => '40'],
                'queued_at' => null,
                'sent_at' => null,
                'delivered_at' => null,
                'read_at' => null,
                'clicked_at' => null,
                'replied_at' => null,
                'failed_at' => null,
            ];

            if (($this->marketingConsent[$contactId] ?? 'none') !== 'granted') {
                // The send guard suppressed everyone without marketing consent.
                $row['status'] = 'suppressed';
                $row['suppression_reason'] = 'no_consent';
            } elseif ($forced < count($otherReasons)) {
                // Guarantee every other suppression reason appears once.
                $row['status'] = 'suppressed';
                $row['suppression_reason'] = $otherReasons[$forced];
                $forced++;
            } else {
                $roll = fake()->numberBetween(1, 100);
                $status = match (true) {
                    $roll <= 10 => 'sent',
                    $roll <= 45 => 'delivered',
                    $roll <= 75 => 'read',
                    $roll <= 85 => 'clicked',
                    $roll <= 92 => 'replied',
                    default => 'failed',
                };

                $row['status'] = $status;
                $row['queued_at'] = $queuedAt;

                if ($status === 'failed') {
                    $row['error_code'] = fake()->randomElement([131026, 131049]);
                    $row['failed_at'] = $queuedAt->copy()->addSeconds(3);
                } else {
                    $row['wamid'] = $this->wamid();
                    $row['cost_minor'] = 420;
                    $spent += 420;
                    $row['sent_at'] = $queuedAt->copy()->addSeconds(2);

                    if (in_array($status, ['delivered', 'read', 'clicked', 'replied'], true)) {
                        $row['delivered_at'] = $queuedAt->copy()->addSeconds(9);
                    }
                    if (in_array($status, ['read', 'clicked', 'replied'], true)) {
                        $row['read_at'] = $queuedAt->copy()->addMinutes(fake()->numberBetween(1, 180));
                    }
                    if ($status === 'clicked') {
                        $row['clicked_at'] = $queuedAt->copy()->addMinutes(fake()->numberBetween(2, 240));
                    }
                    if ($status === 'replied') {
                        $row['replied_at'] = $queuedAt->copy()->addMinutes(fake()->numberBetween(2, 300));
                    }
                }
            }

            $stats[$row['status']] = ($stats[$row['status']] ?? 0) + 1;
            $rows[] = $row;
        }

        $this->insertRows('campaign_recipients', $rows);

        $campaign->update([
            'stats' => ['recipients' => count($rows)] + $stats,
            'spent_minor' => $spent,
        ]);
    }

    /**
     * Mid-send campaign: pending → queued → sent → delivered.
     */
    private function seedWeekendRecipients(Campaign $campaign): void
    {
        /** @var CarbonInterface $startedAt */
        $startedAt = $campaign->started_at;
        $rows = [];

        foreach (array_slice($this->contacts, 400, 150) as $i => $contact) {
            $status = match (true) {
                $i < 45 => 'delivered',
                $i < 90 => 'sent',
                $i < 110 => 'queued',
                default => 'pending',
            };

            $queuedAt = $startedAt->copy()->addSeconds($i * 5);
            $sent = in_array($status, ['sent', 'delivered'], true);

            $rows[] = [
                'tenant_id' => $this->tenantId,
                'campaign_id' => $campaign->id,
                'contact_id' => $contact['id'],
                'message_id' => null,
                'wamid' => $sent ? $this->wamid() : null,
                'status' => $status,
                'suppression_reason' => null,
                'error_code' => null,
                'cost_minor' => $sent ? 420 : null,
                'variables' => ['1' => $contact['profile_name']],
                'queued_at' => $status === 'pending' ? null : $queuedAt,
                'sent_at' => $sent ? $queuedAt->copy()->addSeconds(2) : null,
                'delivered_at' => $status === 'delivered' ? $queuedAt->copy()->addSeconds(10) : null,
                'read_at' => null,
                'clicked_at' => null,
                'replied_at' => null,
                'failed_at' => null,
            ];
        }

        $this->insertRows('campaign_recipients', $rows);

        $campaign->update(['stats' => ['recipients' => count($rows)], 'spent_minor' => 90 * 420]);
    }

    /**
     * 40 orders with payments covering every ioTec status, plus 2 standalone
     * disbursements and coherent append-only payment_events ladders.
     *
     * @param  array<int, array<string, mixed>>  $conversations
     */
    private function seedCommerce(array $conversations): void
    {
        $wallet = IotecWalletFactory::new()->createOne(['name' => 'Luminous Demo Collections']);
        $statuses = PaymentStatus::cases();
        $eventRows = [];

        for ($i = 0; $i < 40; $i++) {
            $paymentStatus = $statuses[$i % count($statuses)];
            $contact = $this->contacts[$i % 100];
            $conversation = $i < count($conversations) ? $conversations[$i] : null;

            $order = OrderFactory::new()->createOne([
                'contact_id' => $contact['id'],
                'conversation_id' => $conversation['id'] ?? null,
                'status' => $this->orderStatusFor($paymentStatus),
                'paid_at' => $paymentStatus === PaymentStatus::Success ? now()->subHours(fake()->numberBetween(1, 96)) : null,
                'cancelled_at' => $paymentStatus === PaymentStatus::Cancelled ? now()->subHours(fake()->numberBetween(1, 96)) : null,
            ]);

            $payment = PaymentFactory::new()->createOne([
                'order_id' => $order->id,
                'contact_id' => $contact['id'],
                'wallet_id' => $wallet->id,
                'amount_minor' => $order->total_minor,
                'payer' => $contact['wa_id'],
                'payer_name' => $contact['display_name'],
                'status' => $paymentStatus,
                'status_message' => $this->paymentStatusMessage($paymentStatus),
                'processed_at' => $this->isTerminal($paymentStatus) ? now()->subHours(fake()->numberBetween(1, 48)) : null,
            ]);

            $eventRows = array_merge($eventRows, $this->paymentEventLadder($payment->id, $paymentStatus, $payment->requested_at ?? now()->subHours(2)));
        }

        // Standalone disbursements (order_id stays null).
        foreach ([PaymentStatus::Success, PaymentStatus::AwaitingApproval] as $status) {
            $payment = PaymentFactory::new()->disbursement()->createOne([
                'wallet_id' => $wallet->id,
                'status' => $status,
                'status_message' => $this->paymentStatusMessage($status),
            ]);

            $eventRows = array_merge($eventRows, $this->paymentEventLadder($payment->id, $status, $payment->requested_at ?? now()->subHours(2)));
        }

        $this->insertRows('payment_events', $eventRows);

        // Denormalised order stats on contacts.
        DB::statement(
            "UPDATE contacts SET orders_count = agg.cnt, lifetime_value = agg.total
             FROM (
                 SELECT contact_id, COUNT(*) AS cnt, SUM(total_minor) AS total
                 FROM orders WHERE status IN ('paid', 'completed') GROUP BY contact_id
             ) agg
             WHERE contacts.id = agg.contact_id"
        );
    }

    /**
     * The legal payment_events ladder that ends in the given status.
     *
     * @return array<int, array<string, mixed>>
     */
    private function paymentEventLadder(string $paymentId, PaymentStatus $final, CarbonInterface $requestedAt): array
    {
        $ladder = match ($final) {
            PaymentStatus::Pending => [PaymentStatus::Pending],
            PaymentStatus::SentToVendor => [PaymentStatus::Pending, PaymentStatus::SentToVendor],
            PaymentStatus::Success => [PaymentStatus::Pending, PaymentStatus::SentToVendor, PaymentStatus::Success],
            PaymentStatus::Failed => [PaymentStatus::Pending, PaymentStatus::SentToVendor, PaymentStatus::Failed],
            PaymentStatus::AwaitingApproval => [PaymentStatus::Pending, PaymentStatus::AwaitingApproval],
            PaymentStatus::RolledBack => [PaymentStatus::Pending, PaymentStatus::SentToVendor, PaymentStatus::Success, PaymentStatus::RolledBack],
            PaymentStatus::Scheduled => [PaymentStatus::Scheduled],
            PaymentStatus::Cancelled => [PaymentStatus::Pending, PaymentStatus::Cancelled],
            PaymentStatus::Rejected => [PaymentStatus::Pending, PaymentStatus::Rejected],
        };

        $rows = [];

        foreach ($ladder as $step => $status) {
            $occurredAt = $requestedAt->copy()->addMinutes($step * 2);

            $rows[] = [
                'tenant_id' => $this->tenantId,
                'payment_id' => $paymentId,
                'status' => $status,
                'status_code' => $status === PaymentStatus::Failed ? 'TARGET_AUTHORIZATION_ERROR' : null,
                'status_message' => $this->paymentStatusMessage($status),
                'source' => $step === 0 ? 'callback' : fake()->randomElement(['callback', 'poll']),
                'raw' => ['status' => $status->value, 'statusMessage' => $this->paymentStatusMessage($status)],
                'occurred_at' => $occurredAt,
                'received_at' => $occurredAt->copy()->addSeconds(2),
            ];
        }

        return $rows;
    }

    private function paymentStatusMessage(PaymentStatus $status): string
    {
        return match ($status) {
            PaymentStatus::Pending => 'Transaction pending',
            PaymentStatus::SentToVendor => 'Request sent to vendor',
            PaymentStatus::Success => 'Transaction completed successfully',
            PaymentStatus::Failed => 'Target authorization error',
            PaymentStatus::AwaitingApproval => 'Awaiting approval on the portal',
            PaymentStatus::RolledBack => 'Transaction rolled back',
            PaymentStatus::Scheduled => 'Scheduled for later execution',
            PaymentStatus::Cancelled => 'Cancelled before dispatch',
            PaymentStatus::Rejected => 'Rejected by compliance checks',
        };
    }

    private function isTerminal(PaymentStatus $status): bool
    {
        return in_array($status, [
            PaymentStatus::Success,
            PaymentStatus::Failed,
            PaymentStatus::RolledBack,
            PaymentStatus::Cancelled,
            PaymentStatus::Rejected,
        ], true);
    }

    private function orderStatusFor(PaymentStatus $status): OrderStatus
    {
        return match ($status) {
            PaymentStatus::Success => fake()->boolean(60) ? OrderStatus::Paid : OrderStatus::Completed,
            PaymentStatus::Cancelled => OrderStatus::Cancelled,
            PaymentStatus::RolledBack => OrderStatus::Refunded,
            default => OrderStatus::PendingPayment,
        };
    }

    /**
     * Contact + conversation labels with pivot rows.
     *
     * @param  array<int, array<string, mixed>>  $conversations
     */
    private function seedLabels(array $conversations): void
    {
        $creator = $this->users[TenantRole::Admin->value];

        $contactLabels = collect(['VIP' => '#f59e0b', 'Wholesale' => '#3b82f6', 'New customer' => '#10b981', 'Follow up' => '#ef4444'])
            ->map(fn (string $color, string $name) => LabelFactory::new()->createOne([
                'name' => $name,
                'color' => $color,
                'created_by' => $creator->id,
            ]))
            ->values();

        $conversationLabels = collect(['Complaint' => '#dc2626', 'Priority' => '#7c3aed'])
            ->map(fn (string $color, string $name) => LabelFactory::new()->forConversations()->createOne([
                'name' => $name,
                'color' => $color,
                'created_by' => $creator->id,
            ]))
            ->values();

        $contactPivot = [];
        foreach (array_slice($this->contacts, 0, 200) as $i => $contact) {
            $contactPivot[] = [
                'label_id' => $contactLabels[$i % $contactLabels->count()]->id,
                'contact_id' => $contact['id'],
            ];
        }
        $this->insertRows('contact_label', $contactPivot);

        $conversationPivot = [];
        foreach (array_slice($conversations, 0, 12) as $i => $conversation) {
            $conversationPivot[] = [
                'label_id' => $conversationLabels[$i % $conversationLabels->count()]->id,
                'conversation_id' => $conversation['id'],
            ];
        }
        $this->insertRows('conversation_label', $conversationPivot);
    }

    /**
     * A starter set of canned replies.
     */
    private function seedCannedReplies(): void
    {
        $creator = $this->users[TenantRole::Supervisor->value];

        $replies = [
            ['/hi', 'Greeting', 'Hi {{contact.first_name}}! Thanks for reaching out to Luminous Demo Store. How can we help today?'],
            ['/thanks', 'Thank you', 'Thank you {{contact.first_name}} — we appreciate your business!'],
            ['/hours', 'Opening hours', 'We are open Monday to Saturday, 9am–7pm EAT. Orders placed on WhatsApp are processed same day.'],
            ['/delivery', 'Delivery info', 'We deliver within Kampala for UGX 5,000 (same day) and countrywide in 2–3 days via bus courier.'],
            ['/payment', 'Payment options', 'You can pay via MTN MoMo or Airtel Money — we will send you a secure payment prompt.'],
            ['/sorry', 'Apology', 'We are really sorry about that, {{contact.first_name}}. Let me look into it right away.'],
        ];

        foreach ($replies as [$shortcut, $title, $body]) {
            CannedReplyFactory::new()->createOne([
                'shortcut' => $shortcut,
                'title' => $title,
                'body' => $body,
                'variables' => str_contains($body, '{{contact.first_name}}') ? ['contact.first_name'] : [],
                'created_by' => $creator->id,
            ]);
        }
    }

    /**
     * Build one consent event row for bulk insertion.
     *
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function consentRow(string $contactId, string $scope, string $state, string $source, array $evidence, CarbonInterface $occurredAt): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'contact_id' => $contactId,
            'scope' => $scope,
            'state' => $state,
            'source' => $source,
            'evidence' => $evidence,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
        ];
    }

    /**
     * Chunked bulk insert with scalar normalisation (enums, dates, json).
     * Raw inserts bypass Eloquent, so every row must carry tenant_id itself
     * where the table requires it.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function insertRows(string $table, array $rows): void
    {
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table($table)->insert(array_map(
                fn (array $row): array => array_map($this->toScalar(...), $row),
                $chunk,
            ));
        }
    }

    private function toScalar(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:sP'),
            is_array($value), $value instanceof \stdClass => json_encode($value),
            default => $value,
        };
    }

    /**
     * A WhatsApp message id: `wamid.` + base64 blob.
     */
    private function wamid(): string
    {
        return 'wamid.'.base64_encode(random_bytes(24));
    }
}
