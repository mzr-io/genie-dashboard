<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The editor's password was wrong (HTTP 422, field `confirm_password`). */
final class PasswordNotConfirmed extends RuntimeException {}
