<?php

namespace App\Modules\Connector\Contracts;

/** Reads the Sample Response of a `sample_fetch` Operation (Story 2.10). */
interface Samples
{
    /**
     * The sample, only for the membership that asked and only while it is current: the Operation must be of this Endpoint,
     * succeeded, not expired, and the Endpoint must still be at the revision that was tested. Anyone else, another Workspace,
     * an expired, failed or stale Operation and a blob that is gone all read the same: null.
     *
     * @param  bool  $mayPreviewAsUser  whether the reader holds `data.preview_as_user`; a Fetch as user response needs it
     *
     * @throws SamplePreviewDenied the sample is a Fetch as user response and the reader may not preview as a user
     */
    public function read(string $workspaceId, string $membershipId, string $dataSourceId, string $endpointId, string $operationId, bool $mayPreviewAsUser = false): ?Sample;
}
