<?php

namespace App\Modules\Connector\Application;

use App\Modules\Access\Contracts\MembershipNotFound;
use App\Modules\Access\Contracts\UserContext;
use App\Modules\Connector\Contracts\Endpoint;
use App\Modules\Connector\Contracts\InvalidDataSource;
use App\Modules\Connector\Contracts\UserContextUnresolved;
use App\Platform\Operations\Operation;
use App\Platform\Operations\OperationHandler;
use App\Platform\Operations\OperationOutcome;

/**
 * The `fetch_as_user` Operation (Story 2.13), run by `worker-connector`. It is {@see RunSampleFetch} with one step added before
 * the request is rendered: the target member's values are resolved here, server-side, through the Access contract
 * ({@see UserContext}), and checked with the rules of a save for where each goes (a header value is visible ASCII on one line, a
 * path value is one segment). Any value that is missing (an attribute not set; none or several groups for `user_group`), a member that
 * is no longer active, or a value that does not fit fails closed with `access.context_missing` before any request is made; the
 * summary names only the attribute key ids that are missing, with the reason `context_missing` or, for a value that cannot be
 * sent, the distinct `context_value_invalid`. There is no retry. The response goes only to the requester, as the same sealed
 * blob, through the same read route; resolved values never reach a log, the audit log, `sync_runs`, a summary or the browser.
 */
final class RunFetchAsUser implements OperationHandler
{
    public function __construct(
        private readonly RunSampleFetch $runner,
        private readonly RenderEndpointRequest $renderer,
        private readonly UserContext $context,
    ) {}

    public function handle(Operation $operation, array $input): OperationOutcome
    {
        $target = is_string($input['target_membership_id'] ?? null) ? $input['target_membership_id'] : '';

        return $this->runner->execute($operation, $input, function (Operation $operation, Endpoint $endpoint, array $values) use ($target): array {
            try {
                $resolved = $this->context->resolve($operation->workspaceId, $target, $this->renderer->userBindings($endpoint));
            } catch (MembershipNotFound) {
                throw new UserContextUnresolved('member_unavailable');
            }

            if (! $resolved->complete()) {
                throw new UserContextUnresolved(UserContextUnresolved::MISSING, $resolved->missing);
            }

            try {
                return $this->renderer->values($endpoint, $values, $resolved->values);
            } catch (InvalidDataSource $e) {
                // Only the reason survives: a refusal never carries a value, and a name is not needed to act on it.
                $bad = array_filter($e->reasons, fn (string $reason): bool => $reason === 'context-value-invalid');

                throw new UserContextUnresolved($bad === [] ? 'values_invalid' : UserContextUnresolved::INVALID);
            }
        });
    }

    public function cleanup(Operation $operation): void
    {
        $this->runner->cleanup($operation);
    }
}
