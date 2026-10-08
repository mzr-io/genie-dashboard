<?php

use App\Modules\Connector\Contracts\CredentialScheme;
use App\Modules\Connector\Contracts\DataSource;
use App\Modules\Connector\Contracts\FetchRequest;
use App\Modules\Connector\Contracts\SecretRef;

// Story 2.4: a FetchRequest carries secret_refs and the scheme only (the DB-backed contract test is in DataSourceSecretsTest).
it('serialises ids, a sanitized template, parameter names, the scheme and refs only', function () {
    $request = new FetchRequest('w', 'd', 'e', 'https://user:pw@api.example.com/v1/{id}?key=CANARY#frag', ['id'], CredentialScheme::Bearer, [new SecretRef('s1', 'bearer_token')]);

    expect($request->toArray())->toBe([
        'v' => 5, 'workspace_id' => 'w', 'data_source_id' => 'd', 'endpoint_id' => 'e', 'url_template' => 'https://api.example.com/v1/{id}',
        'parameter_names' => ['id'], 'credential_scheme' => 'bearer', 'secret_refs' => [['id' => 's1', 'slot' => 'bearer_token', 'purpose' => 'cred', 'secret_version' => 1]],
        'headers' => [], 'api_key_name' => null, 'api_key_placement' => null, 'timeout_seconds' => null, 'method' => 'GET', 'max_response_bytes' => null,
        'oauth_token_url' => null, 'oauth_client_id' => null, 'oauth_scope' => null, 'secret_version' => null,
        'query_count' => 0, 'endpoint_header_count' => 0, 'has_body' => false, 'read_only_query' => false,
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

// Story 2.10: version 5 carries what an Endpoint test sends, and none of it is ever serialised or dumped.
it('carries the rendered URL, query, headers and body of a test but serialises only the template, the names and counts', function () {
    $request = new FetchRequest(
        'w', 'd', 'e', 'https://api.example.com/v1/customers/{id}', ['id', 'q'], CredentialScheme::None, [],
        method: 'POST', url: 'https://api.example.com/v1/customers/CANARY-PATH', queryPairs: [['q', 'CANARY-QUERY']],
        endpointHeaders: [['name' => 'X-A', 'value' => 'CANARY-HEADER']], body: '{"a":"CANARY-BODY"}', idempotencyKey: 'op-1', readOnlyQuery: true,
    );

    $dump = json_encode($request).print_r($request, true).var_export($request->toArray(), true);

    expect($request->url)->toBe('https://api.example.com/v1/customers/CANARY-PATH')
        ->and($request->queryPairs)->toBe([['q', 'CANARY-QUERY']])
        ->and($request->toArray())->toMatchArray([
            'v' => 5, 'url_template' => 'https://api.example.com/v1/customers/{id}', 'parameter_names' => ['id', 'q'],
            'method' => 'POST', 'query_count' => 1, 'endpoint_header_count' => 1, 'has_body' => true, 'read_only_query' => true,
        ])
        ->and($dump)->not->toContain('CANARY');
});
