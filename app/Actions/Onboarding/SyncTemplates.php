<?php

namespace App\Actions\Onboarding;

use App\Enums\TemplateCategory;
use App\Models\OnboardingSession;
use App\Models\Template;
use App\Models\WabaAccount;
use App\Services\Meta\CredentialResolver;
use App\Support\AuditLog;

/**
 * Step 5 — initial template pull so M3 is populated on arrival
 * (docs/modules/m0-onboarding.md §1 step 5,
 * docs/reference/whatsapp-cloud-api.md §4).
 */
class SyncTemplates extends OnboardingStep
{
    public function __construct(private readonly CredentialResolver $credentials) {}

    public function runsAt(): string
    {
        return OnboardingStatus::SYNCING;
    }

    public function handle(OnboardingSession $session, ?string $code = null): void
    {
        $this->assertFinishPayload($session);

        $wabaId = (string) $session->waba_id;
        $account = WabaAccount::query()->where('waba_id', $wabaId)->firstOrFail();
        $client = $this->credentials->businessClient();

        $synced = 0;
        $after = null;

        do {
            $params = [
                'fields' => 'id,name,language,category,status,components,quality_score',
                'limit' => 100,
            ];

            if ($after !== null) {
                $params['after'] = $after;
            }

            $page = $client->get("{$wabaId}/message_templates", $params);

            foreach ((array) ($page['data'] ?? []) as $template) {
                $synced += $this->upsertTemplate($account, (array) $template);
            }

            $paging = (array) ($page['paging'] ?? []);
            $cursors = (array) ($paging['cursors'] ?? []);
            $next = isset($paging['next']) ? (string) ($cursors['after'] ?? '') : '';

            // Guard against a misbehaving API echoing the same cursor back.
            $after = ($next !== '' && $next !== $after) ? $next : null;
        } while ($after !== null);

        AuditLog::record('onboarding.templates_synced', subject: $session, context: [
            'waba_id' => $wabaId,
            'templates' => $synced,
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $template
     */
    private function upsertTemplate(WabaAccount $account, array $template): int
    {
        $name = (string) ($template['name'] ?? '');

        if ($name === '') {
            return 0;
        }

        $qualityScore = $template['quality_score'] ?? null;

        if (is_array($qualityScore)) {
            $qualityScore = $qualityScore['score'] ?? null;
        }

        $row = Template::query()->firstOrNew([
            'waba_account_id' => $account->id,
            'name' => $name,
            'language' => (string) ($template['language'] ?? 'en'),
        ]);

        if (! $row->exists) {
            $row->forceFill(['variable_map' => []]); // derived later, in M3
        }

        $row->fill([
            'meta_template_id' => isset($template['id']) ? (string) $template['id'] : null,
            'category' => TemplateCategory::from(strtoupper((string) ($template['category'] ?? ''))),
            'status' => (string) ($template['status'] ?? 'PENDING'),
            'quality_score' => $qualityScore !== null ? (string) $qualityScore : null,
            'components' => (array) ($template['components'] ?? []),
            'last_synced_at' => now(),
        ])->save();

        return 1;
    }
}
