<?php

namespace App\Http\Requests\Teams;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The invitee sets a name and a password. There is deliberately no email
 * field: the address is the invitation's, and accepting an emailed address
 * from the form would let someone redirect an invitation to themselves.
 */
class JoinTeamRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }
}
