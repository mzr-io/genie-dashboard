<?php

use App\Platform\Audit\AuditAction;

it('follows the {module}.{noun}.{past_verb} grammar in every case', function () {
    foreach (AuditAction::cases() as $case) {
        expect(preg_match(AuditAction::GRAMMAR, $case->value))->toBe(1, "AuditAction::{$case->name} = {$case->value} breaks the grammar");
        expect(count(explode('.', $case->value)))->toBe(3, "{$case->name} must have three parts")
            ->and($case->value)->toBe(strtolower($case->value));
    }
});

it('has unique values and throws on an unknown string', function () {
    $values = array_map(fn ($c) => $c->value, AuditAction::cases());
    expect($values)->toBe(array_values(array_unique($values)));
    expect(fn () => AuditAction::fromString('access.role.exploded'))->toThrow(InvalidArgumentException::class);
    expect(AuditAction::fromString('access.role.changed'))->toBe(AuditAction::AccessRoleChanged);
});

it('names the module in the first part', function () {
    expect(AuditAction::IdentityInvitationAccepted->module())->toBe('identity');
});
