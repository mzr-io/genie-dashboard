<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Password re-confirmation for operator commands. No operator account exists yet, so the password is checked against the
 * bcrypt hash in `dashflow.egress.operator_password_hash` (`DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH`, pending_input). Unset,
 * or not a bcrypt hash, the command refuses. The prompt is hidden and three wrong answers end it.
 */
trait ConfirmsOperatorPassword
{
    private const PASSWORD_ATTEMPTS = 3;

    private function operatorPasswordHash(): ?string
    {
        $hash = config('dashflow.egress.operator_password_hash.value');

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    /** False, with the reason printed, while the hash setting is unset: the command must refuse before anything else. */
    private function passwordConfigured(): bool
    {
        $hash = $this->operatorPasswordHash();

        if ($hash !== null) {
            if (password_get_info($hash)['algoName'] === 'bcrypt') {
                return true;
            }

            $this->components->error('DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH is not a bcrypt hash, so this command refuses to run.');

            return false;
        }

        $this->components->error('DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH is not set, so this command refuses to run. Set it to a bcrypt hash of the operator password.');

        return false;
    }

    /** True once the operator typed the right password; prints the reason and returns false otherwise. */
    private function confirmedOperator(): bool
    {
        $hash = $this->operatorPasswordHash();

        if ($hash === null) {
            return $this->passwordConfigured();
        }

        for ($attempt = 1; $attempt <= self::PASSWORD_ATTEMPTS; $attempt++) {
            $given = (string) $this->secret('Operator password');

            try {
                if ($given !== '' && Hash::check($given, $hash)) {
                    return true;
                }
            } catch (Throwable) {
                $this->components->error('DASHFLOW_EGRESS_OPERATOR_PASSWORD_HASH is not a bcrypt hash, so this command refuses to run.');

                return false;
            }

            $this->components->error('Wrong password.');
        }

        $this->components->error('Three wrong passwords: nothing was changed.');

        return false;
    }
}
