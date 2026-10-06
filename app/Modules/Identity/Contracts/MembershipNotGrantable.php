<?php

namespace App\Modules\Identity\Contracts;

use RuntimeException;

/** The user already has a membership that is not active (suspended or removed); an invitation must not revive it. */
final class MembershipNotGrantable extends RuntimeException {}
