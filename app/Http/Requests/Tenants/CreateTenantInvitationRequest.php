<?php

namespace App\Http\Requests\Tenants;

use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Rules\UniqueTenantInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTenantInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $tenant = $this->route('tenant');

        abort_if(! $tenant instanceof Tenant, 404);

        return [
            'email' => ['required', 'string', 'email', 'max:255', new UniqueTenantInvitation($tenant)],
            'role' => ['required', 'string', Rule::enum(TenantRole::class)],
        ];
    }
}
