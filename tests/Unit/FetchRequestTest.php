<?php

use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\SecretRef;

// Story 2.4: a FetchRequest carries secret_refs and the scheme only (the DB-backed contract test is in DataSourceSecretsTest).
it('serialises ids, a sanitized template, parameter names, the scheme and refs only', function () {
    $request = new FetchRequest('w', 'd', 'e', 'https://user:pw@api.example.com/v1/{id}?key=CANARY#frag', ['id'], CredentialScheme::Bearer, [new SecretRef('s1', 'bearer_token')]);

    expect($request->toArray())->toBe([
        'v' => 1, 'workspace_id' => 'w', 'data_source_id' => 'd', 'endpoint_id' => 'e', 'url_template' => 'https://api.example.com/v1/{id}',
        'parameter_names' => ['id'], 'credential_scheme' => 'bearer', 'secret_refs' => [['id' => 's1', 'slot' => 'bearer_token', 'purpose' => 'cred']],
    ])->and(json_encode($request))->not->toContain('CANARY')->not->toContain('pw@');
});
