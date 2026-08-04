<?php

namespace App\Http\Requests\Teams;

use App\Enums\TeamRole;
use App\Rules\UniqueTeamInvitation;
use App\Support\Facades\Teams;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTeamInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', new UniqueTeamInvitation(Teams::currentOrFail())],
            'role' => ['required', 'string', Rule::enum(TeamRole::class)],
        ];
    }
}
