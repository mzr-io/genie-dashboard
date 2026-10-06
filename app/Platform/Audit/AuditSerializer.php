<?php

namespace App\Platform\Audit;

/**
 * A module's allowlist of audited fields. Only listed fields are stored; the kernel calls no module,
 * so each module registers its serializer with AuditSerializers.
 */
interface AuditSerializer
{
    /** The module name as it appears in its audit actions (`access`). */
    public function module(): string;

    /**
     * The audited fields and how each is stored. Fields not listed here are dropped.
     *
     * @return array<string, AuditField>
     */
    public function fields(): array;
}
