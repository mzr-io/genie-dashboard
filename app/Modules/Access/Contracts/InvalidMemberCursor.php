<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** The cursor is malformed or belongs to another sort. */
final class InvalidMemberCursor extends RuntimeException {}
