<?php

namespace App\Modules\Connector\Contracts;

use App\Platform\Operations\OperationOutcome;

/**
 * What a validation run of an Endpoint made of the live API (Story 3.18). `outcome` is the summary of the fetch whose body the caller reads with
 * {@see EndpointValidations::take()}. For an Endpoint bound to the member's context, `unboundRefused` is the negative check: true when a fetch
 * without the member's attribute was refused by the platform before any request was made (`access.context_missing`), false when it was not, so the
 * Endpoint could be published unbound; null for an Endpoint that is not user-bound.
 */
final readonly class EndpointValidationRun
{
    public function __construct(public OperationOutcome $outcome, public ?bool $unboundRefused = null) {}
}
