<?php

namespace App\Http\Controllers\Settings;

use App\Actions\WhatsApp\DisconnectWhatsApp;
use App\Actions\WhatsApp\RefreshWhatsAppConnection;
use App\Actions\WhatsApp\UpdateBusinessProfile;
use App\Enums\WhatsAppVertical;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateWhatsAppProfileRequest;
use App\Models\PhoneNumber;
use App\Models\WabaAccount;
use App\Services\Meta\Exceptions\CredentialMissing;
use App\Services\Meta\Exceptions\CredentialRevoked;
use App\Services\Meta\Exceptions\GraphApiException;
use App\Support\Facades\Teams;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The team's WhatsApp connection (docs/modules/m0-onboarding.md §7): one page,
 * because a team has one WABA and one number (D-020). Four panels — connected
 * account, business profile, billing, disconnect. Team scoping is automatic
 * (BelongsToTeam + RLS).
 */
class WhatsAppController extends Controller
{
    /**
     * Display the team's WABA, its number, and the editable business profile.
     */
    public function show(Request $request): Response
    {
        $wabaAccount = WabaAccount::query()->with('phoneNumber')->first();
        $number = $wabaAccount?->phoneNumber;
        $user = $request->user();
        $team = Teams::current();

        return Inertia::render('settings/whatsapp', [
            'wabaAccount' => $wabaAccount === null ? null : [
                'id' => $wabaAccount->id,
                'name' => $wabaAccount->name,
                'wabaId' => $wabaAccount->waba_id,
                'paymentReady' => $wabaAccount->payment_ready,
                // account_review_status, rendered as "Account status".
                'accountReviewStatus' => $wabaAccount->review_status,
                'businessVerificationStatus' => $wabaAccount->business_verification_status,
                // Portfolio-level, from whatsapp_business_manager_messaging_limit.
                'portfolioMessagingLimit' => $wabaAccount->portfolio_messaging_limit,
            ],
            'phoneNumber' => $number === null ? null : [
                'id' => $number->id,
                'displayPhoneNumber' => $number->display_phone_number,
                'verifiedName' => $number->verified_name,
                'qualityRating' => $number->quality_rating,
                'throughputLevel' => $number->throughput_level,
                'platformType' => $number->platform_type,
                'isOnBizApp' => $number->is_on_biz_app,
                // Two-step verification. VERIFIED|UNVERIFIED, nothing else.
                'codeVerificationStatus' => $number->code_verification_status,
                // Display-name review state — a different field entirely.
                'nameStatus' => $number->name_status,
                // Meta's `status` on the number node. `status` below is our
                // own lifecycle value and means something different.
                'connectionStatus' => $number->connection_status,
                'pinSet' => $number->pin_set,
                'status' => $number->status,
                'lastSyncedAt' => $number->last_synced_at?->toISOString(),
            ],
            'profile' => $number === null ? null : $this->profileProps($number),
            'verticals' => WhatsAppVertical::options(),
            'canManage' => $user !== null && $team !== null && Gate::forUser($user)->allows('manageWhatsApp', $team),
            'links' => [
                'whatsappManager' => $this->metaLink('meta.whatsapp_manager_url', $wabaAccount),
                'billing' => $this->metaLink('meta.billing_hub_url', $wabaAccount),
            ],
        ]);
    }

    /**
     * Re-read the connected-account panel from Graph and persist it.
     */
    public function refresh(Request $request, RefreshWhatsAppConnection $refresh): RedirectResponse
    {
        Gate::authorize('manageWhatsApp', Teams::currentOrFail());

        [$account, $number] = $this->connection();

        return $this->guarded(function () use ($refresh, $account, $number, $request): RedirectResponse {
            $refresh->handle($account, $number, $request->user());

            Inertia::flash('toast', ['type' => 'success', 'message' => __('WhatsApp connection refreshed.')]);

            return to_route('whatsapp.show');
        });
    }

    /**
     * Save the business profile, then re-read it — the write echoes nothing
     * back but `{"success": true}` (docs/reference §5).
     */
    public function updateProfile(UpdateWhatsAppProfileRequest $request, UpdateBusinessProfile $update): RedirectResponse
    {
        [, $number] = $this->connection();

        return $this->guarded(function () use ($request, $update, $number): RedirectResponse {
            /** @var array{about?: string|null, address?: string|null, description?: string|null, email?: string|null, vertical?: string|null, websites?: array<int, string>} $fields */
            $fields = $request->safe()->only(['about', 'address', 'description', 'email', 'vertical', 'websites']);

            $update->handle($number, $fields, $request->file('profile_picture'), $request->user());

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Business profile saved.')]);

            return to_route('whatsapp.show');
        });
    }

    /**
     * Clear the connection from this workspace. The number is left registered
     * on the Cloud API — nothing here is refused by Meta, so a Coexistence
     * number disconnects like any other.
     */
    public function disconnect(Request $request, DisconnectWhatsApp $disconnect): RedirectResponse
    {
        Gate::authorize('manageWhatsApp', Teams::currentOrFail());

        [$account, $number] = $this->connection();

        return $this->guarded(function () use ($disconnect, $account, $number, $request): RedirectResponse {
            $unsubscribed = $disconnect->handle($account, $number, $request->user());

            Inertia::flash('toast', $unsubscribed
                ? ['type' => 'success', 'message' => __('WhatsApp disconnected. The number is still registered on the Cloud API.')]
                // The disconnect itself went through; only the unsubscribe
                // failed. Say so plainly — webhooks may keep arriving until
                // the client removes the app in Business Manager.
                : ['type' => 'warning', 'message' => __('WhatsApp disconnected, but Meta would not unsubscribe our app from this account. Remove Luminous in Meta Business Settings to stop the notifications.')]);

            return to_route('whatsapp.show');
        });
    }

    /**
     * The team's single WABA and number, or a 404 when nothing is connected.
     *
     * @return array{0: WabaAccount, 1: PhoneNumber}
     */
    private function connection(): array
    {
        $account = WabaAccount::query()->with('phoneNumber')->first();
        $number = $account?->phoneNumber;

        abort_if($account === null || $number === null, 404, 'This workspace has no connected WhatsApp number.');

        return [$account, $number];
    }

    /**
     * Everything Graph can throw at us is rendered as a readable message on
     * the page — never a 500 (docs/modules/m0-onboarding.md §2, §8).
     *
     * @param  callable(): RedirectResponse  $call
     */
    private function guarded(callable $call): RedirectResponse
    {
        try {
            return $call();
        } catch (GraphApiException $e) {
            return back()->withErrors(['meta' => $this->metaMessage($e)]);
        } catch (CredentialRevoked) {
            return back()->withErrors([
                'meta' => __('This workspace\'s WhatsApp access has been revoked by Meta. Reconnect WhatsApp to continue.'),
            ]);
        } catch (CredentialMissing) {
            return back()->withErrors([
                'meta' => __('This workspace is not connected to WhatsApp.'),
            ]);
        }
    }

    /**
     * Meta's own words where it supplies them, our own where it does not.
     * The raw error payload is preserved on the exception either way.
     */
    private function metaMessage(GraphApiException $e): string
    {
        foreach (['error_user_msg', 'error_user_title', 'message'] as $key) {
            $value = $e->error[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return __('WhatsApp rejected the request. Try again in a few minutes.');
    }

    /**
     * @return array<string, mixed>
     */
    private function profileProps(PhoneNumber $number): array
    {
        $profile = $number->profile;

        /** @var array<int, string> $websites */
        $websites = array_values(array_map(
            fn (mixed $website): string => (string) $website,
            (array) ($profile['websites'] ?? []),
        ));

        return [
            'about' => $this->nullableString($profile, 'about'),
            'address' => $this->nullableString($profile, 'address'),
            'email' => $this->nullableString($profile, 'email'),
            'description' => $this->nullableString($profile, 'description'),
            'vertical' => $this->nullableString($profile, 'vertical'),
            // Always two slots: the form renders exactly two inputs, because
            // two is Meta's hard maximum.
            'websites' => [$websites[0] ?? '', $websites[1] ?? ''],
            'profilePictureUrl' => $this->nullableString($profile, 'profile_picture_url'),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function nullableString(array $profile, string $key): ?string
    {
        $value = $profile[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A Meta-hosted link, scoped to the client's own business portfolio when
     * we know it.
     */
    private function metaLink(string $configKey, ?WabaAccount $account): string
    {
        $url = (string) config($configKey);

        if ($account === null || $account->owner_business_id === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'business_id' => $account->owner_business_id,
        ]);
    }
}
