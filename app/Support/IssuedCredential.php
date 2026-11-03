<?php

namespace App\Support;

use App\Models\ApplicationCredential;

/**
 * A freshly issued credential together with its plaintext token.
 *
 * The plaintext exists only for the lifetime of the request that created it:
 * it is shown to the administrator once and never recoverable afterwards.
 */
readonly class IssuedCredential
{
    public function __construct(
        public ApplicationCredential $credential,
        public string $plainTextToken,
    ) {}
}
