<?php

use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\SecretRef;

// Story 2.4: a FetchRequest carries secret_refs and the scheme only (the DB-backed contract test is in DataSourceSecretsTest).
it('serialises ids, a sanitized template, parameter names, the scheme and refs only', function () {
    $request = new FetchRequest('w', 'd', 'e', 'https://user:pw@api.example.com/v1/{id}?key=CANARY#frag', ['id'], CredentialScheme::Bearer, [new SecretRef('s1', 'bearer_token')]);

    expect($request->toArray())->toBe([
        'v' => 3, 'workspace_id' => 'w', 'data_source_id' => 'd', 'endpoint_id' => 'e', 'url_template' => 'https://api.example.com/v1/{id}',
        'parameter_names' => ['id'], 'credential_scheme' => 'bearer', 'secret_refs' => [['id' => 's1', 'slot' => 'bearer_token', 'purpose' => 'cred']],
        'headers' => [], 'api_key_name' => null, 'api_key_placement' => null, 'timeout_seconds' => null, 'method' => 'GET', 'max_response_bytes' => null,
    ])->and(json_encode($request))->not->toContain('CANARY')->not->toContain('pw@');
});

it('treats only a header flagged secret: true as secret, as the transport does', function () {
    $source = new DataSource(
        'd', 'n', 'https://api.example.com', 'https', 'api.example.com', 443, 'none',
        [['name' => 'X-A', 'value' => 'plain', 'secret' => false], ['name' => 'X-B', 'value' => '', 'secret' => true]],
        null, null, null, false, 1, 't', 't',
    );

    $request = FetchRequest::for('w', $source, 'e', 'https://api.example.com', [], []);

    expect($request->headers)->toBe([['name' => 'X-A', 'value' => 'plain', 'secret' => false], ['name' => 'X-B', 'value' => '', 'secret' => true]]);
});
