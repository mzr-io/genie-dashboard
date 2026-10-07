<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The member's membership is not active, so its access cannot be edited (HTTP 422, reason `membership_inactive`). */
final class MembershipInactive extends RuntimeException {}
