<?php

namespace App\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The signed-in user's single team (D-020) — there is no "current" flag,
 * because there is nothing to be current against.
 */
#[TypeScript]
readonly class UserTeam
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public ?string $role,
        public ?string $roleLabel,
    ) {
        //
    }
}
