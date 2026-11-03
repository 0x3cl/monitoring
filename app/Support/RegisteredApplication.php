<?php

namespace App\Support;

use App\Models\Application;

/**
 * The outcome of a registration: the stored application plus the one-time
 * plaintext credential its integration needs.
 */
readonly class RegisteredApplication
{
    public function __construct(
        public Application $application,
        public IssuedCredential $credential,
    ) {}
}
