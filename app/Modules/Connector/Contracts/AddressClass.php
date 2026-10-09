<?php

namespace App\Modules\Connector\Contracts;

/** What an outbound destination address is: never reachable, reachable only by an operator grant, or public. */
enum AddressClass
{
    /** Loopback, link-local, metadata, unspecified, multicast, mapped, NAT64, 6to4, Teredo, documentation, benchmarking, the deployment's own CIDRs: no grant lifts it. */
    case Undeniable;

    /** RFC 1918, CGNAT and ULA space: allowed only for a Workspace holding an active grant that covers it. */
    case Grantable;

    case Public;
}
