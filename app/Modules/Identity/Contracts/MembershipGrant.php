<?php

namespace App\Modules\Identity\Contracts;

/** The result of granting an invited membership: its ID and what happened to it (`created`, `promoted` or `unchanged`). */
final readonly class MembershipGrant
{
    public const CREATED = 'created';

    public const PROMOTED = 'promoted';

    public const UNCHANGED = 'unchanged';

    public function __construct(public string $membershipId, public string $outcome) {}
}
