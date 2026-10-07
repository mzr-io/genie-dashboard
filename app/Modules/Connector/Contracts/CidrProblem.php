<?php

namespace App\Modules\Connector\Contracts;

/** Why an operator grant or revocation was refused. Nothing is written for any of them. */
enum CidrProblem: string
{
    case Invalid = 'invalid';
    case InvalidReason = 'invalid_reason';
    case Undeniable = 'undeniable';
    case OverlapsUndeniable = 'overlaps_undeniable';
    case OverlapsDeployment = 'overlaps_deployment';
    case NotPrivate = 'not_private';
    case AlreadyGranted = 'already_granted';
    case NotGranted = 'not_granted';
    case UnknownWorkspace = 'unknown_workspace';

    /** The operator-facing explanation. */
    public function message(): string
    {
        return match ($this) {
            self::Invalid => 'The CIDR must be a network such as 10.20.0.0/16 or fd12:3456::/48, with the host bits zero.',
            self::InvalidReason => 'The reason must be 1 to 500 printable characters without line breaks.',
            self::Undeniable => 'That range is undeniable (loopback, link-local, metadata, mapped, reserved and similar): no grant can lift it.',
            self::OverlapsUndeniable => 'That range overlaps an undeniable range: no grant can lift it.',
            self::OverlapsDeployment => "That range overlaps the deployment's own networks, which are never reachable.",
            self::NotPrivate => 'Only a range wholly inside RFC 1918, CGNAT (100.64.0.0/10) or unique local (fc00::/7) space can be granted. Public space needs no grant.',
            self::AlreadyGranted => 'This Workspace already has an active grant covering that range.',
            self::NotGranted => 'This Workspace has no active grant for that range.',
            self::UnknownWorkspace => 'No such Workspace.',
        };
    }
}
