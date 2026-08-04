# 04 — Conventions

## Backend (Laravel 13, PHP 8.4)

### Directory layout

```
app/
├── Actions/                 # one operation per class, public handle()
│   ├── Contacts/
│   ├── Conversations/
│   ├── Messaging/           SendMessage, SendTemplate, MarkRead, TakeThreadControl…
│   ├── Onboarding/
│   ├── Templates/
│   ├── Campaigns/
│   ├── Agent/               MBA config, knowledge sync, connectors
│   ├── Commerce/
│   └── Payments/
├── Data/                    # readonly DTOs (spatie/laravel-data) crossing boundaries
├── Enums/                   # PHP 8.4 backed enums mirroring every Meta/ioTec vocabulary
├── Events/
├── Http/
│   ├── Controllers/
│   │   ├── App/             Inertia pages
│   │   ├── Webhooks/        MetaWebhookController, IotecWebhookController
│   │   └── Connectors/      MBA connector endpoints
│   ├── Middleware/
│   ├── Requests/
│   └── Resources/           Inertia prop shapes
├── Jobs/
├── Listeners/
├── Models/
├── Policies/
├── Services/
│   ├── Meta/
│   │   ├── GraphClient.php          low-level, version-pinned, retry+backoff
│   │   ├── Cloud/                   Messages, Media, PhoneNumbers, Templates, Analytics
│   │   ├── Agent/                   MBA: Eligibility, Onboarding, Settings, Knowledge, Connectors
│   │   └── Signup/                  Embedded Signup token exchange
│   ├── Iotec/
│   │   ├── IotecClient.php          OAuth2 token cache + request signing
│   │   ├── Collections.php
│   │   ├── Disbursements.php
│   │   └── Wallets.php
│   └── RateLimiting/                per-WABA and per-recipient limiters
└── Support/
```

### Rules

1. **Actions are the unit of business logic.** Signature: `handle(...): Result`. No HTTP, no
   `request()`, no `auth()` inside — pass what you need. Controllers, jobs, and console commands all
   call the same Action.
2. **Services never contain business rules.** `Services/Meta/Cloud/Messages::send()` builds a
   request and returns a typed response. It does not check consent or windows.
3. **Every external vocabulary is an enum.** `MessageStatus`, `TemplateCategory`, `PricingCategory`,
   `RequestStatus` (ioTec), `PaymentCategory`, `Vendor`, `ThreadOwner`. Add `tryFromMeta()` that
   returns `null` for unknown values and logs — Meta adds values without notice; never throw.
4. **No raw Graph URLs.** Everything goes through `GraphClient` so the API version, retries, and
   rate-limit header parsing exist in exactly one place.
5. **Strict types everywhere.** `declare(strict_types=1);` in every file. `final` by default.
6. **Money never touches float.** Helpers `Money::fromMajor(string|int, string $currency)` and
   `->minor()`. ioTec sends `double` — convert at the client boundary and never let it inward.
7. **Timestamps are `CarbonImmutable`.** Configure `Date::use(CarbonImmutable::class)`.
8. **Queued jobs are idempotent.** Every job either has a natural key (`wamid`, `provider_id`) or
   uses `WithoutOverlapping` + a dedupe check. Assume every job runs at least twice.
9. **Team context is explicit.** Never read the team from a global inside a job — pass
   `team_id` in the payload and re-establish scope in `handle()`.

### Naming

| Thing | Convention | Example |
|---|---|---|
| Action | Verb + noun | `SendTemplateMessage` |
| Job | Verb + noun + no suffix | `ProcessWebhookDelivery` |
| Event | Past tense | `MessageDelivered`, `ThreadControlPassed` |
| Enum case | Match the wire value where possible | `PricingCategory::MetaBusinessAgent` |
| Migration | `create_<plural>_table` / `add_<x>_to_<y>_table` | |
| Route name | dot namespace | `inbox.conversations.show` |

---

## Frontend (React 19, TypeScript 7, Inertia 3, Tailwind 4, shadcn/ui)

### Directory layout

```
resources/
├── js/
│   ├── app.tsx                  # createInertiaApp
│   ├── ssr.tsx                  # optional SSR entry
│   ├── layouts/
│   │   ├── app-layout.tsx
│   │   ├── inbox-layout.tsx     # three-pane persistent layout
│   │   └── settings-layout.tsx
│   ├── pages/                   # one file per Inertia route, kebab-case dirs
│   │   ├── inbox/index.tsx
│   │   ├── contacts/
│   │   ├── templates/
│   │   ├── campaigns/
│   │   ├── agent/
│   │   ├── commerce/
│   │   └── analytics/
│   ├── components/
│   │   ├── ui/                  # shadcn/ui generated — do not hand-edit
│   │   ├── inbox/               ConversationList, MessageBubble, CswTimer, OwnershipBadge…
│   │   ├── contacts/
│   │   └── shared/
│   ├── hooks/
│   ├── lib/
│   │   ├── utils.ts             cn()
│   │   ├── format.ts            money, dates in team tz, phone
│   │   └── echo.ts              broadcasting client
│   └── types/
│       ├── generated.d.ts       # generated from PHP — never hand-edit
│       └── index.d.ts           # PageProps, shared props
└── css/app.css                  # Tailwind 4 @import + @theme tokens
```

### Rules

1. **Types are generated, not written.** Use `spatie/laravel-typescript-transformer` to emit
   `generated.d.ts` from Data objects and enums. A prop type mismatch must be a compile error.
2. **`SharedProps` are minimal.** `auth.user`, `team`, `permissions`, `unread_counts`, `flash`.
   Everything else is per-page. Shared props are serialised on *every* Inertia response.
3. **Use Inertia 3 deferred props** for anything expensive (analytics panels, message history beyond
   the first page) so the shell paints immediately.
4. **Persistent layouts for the inbox.** `Page.layout` so the conversation list is not remounted on
   navigation — this is what makes the inbox feel native.
5. **Server is the source of truth for lists; client state is ephemeral only.** No Redux/Zustand
   duplicate of server data. Local state is limited to composer drafts, panel widths, filters.
6. **Realtime augments, never replaces, Inertia.** A broadcast event triggers either an optimistic
   local append (new message in the open thread) or a partial reload
   (`router.reload({ only: ['conversations'] })`). Never build a second data path.
7. **shadcn/ui components are vendored.** Add with `npx shadcn@latest add <name>`. Restyle via
   Tailwind tokens in `app.css`, not by editing `components/ui/*` — otherwise updates conflict.
8. **Forms use Inertia's `useForm`.** Validation errors come from Laravel; no client-side schema
   duplication except for instant UX affordances.
9. **Every list is virtualised** past ~100 rows (conversations, contacts, campaign recipients,
   message history). Non-negotiable for teams with 100k contacts.
10. **Optimistic sends.** Render the bubble immediately with `status: 'queued'`, reconcile on the
    broadcast. Show a clear failed state with a retry affordance.

### Tailwind 4

Tokens live in `@theme` in `resources/css/app.css`. No `tailwind.config.js` unless a plugin needs it.
Define semantic tokens (`--color-brand`, `--color-inbound`, `--color-outbound`,
`--color-ai`, `--color-warn`) so white-label theming is a token swap per team.

### Accessibility floor

Keyboard-navigable conversation list, focus trap in dialogs, visible focus rings, `aria-live` on new
message arrival, 4.5:1 contrast minimum. Agents live in this UI for 8 hours a day.

---

## Testing

Pest 4. Details in `06-testing-strategy.md`. The convention that matters here:
**no test hits the network.** `Services/Meta/*` and `Services/Iotec/*` have recorded-fixture fakes
bound in the container for tests.

---

## Git & review

| Rule | Detail |
|---|---|
| Branches | `feat/m1-inbox-composer`, `fix/…`, `chore/…` |
| Commits | Conventional Commits, imperative |
| PR must include | Migration + model + Action + test + doc delta in the same PR |
| Never merge | A PR that changes an external-API assumption without updating `reference/` |
| CI gates | Pint, PHPStan level 8, Pest, `tsc --noEmit`, ESLint, `npm run build` |
