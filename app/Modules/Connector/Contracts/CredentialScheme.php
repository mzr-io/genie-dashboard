<?php

namespace App\Modules\Connector\Contracts;

/** How a call carries its credential: the scheme only, never the value. */
enum CredentialScheme: string
{
    case None = 'none';
    case ApiKeyHeader = 'api_key_header';
    case ApiKeyQuery = 'api_key_query';
    case Bearer = 'bearer';
    case Basic = 'basic';

    public static function forSource(DataSource $source): self
    {
        return match ($source->authType) {
            'api_key' => $source->apiKeyPlacement === 'query' ? self::ApiKeyQuery : self::ApiKeyHeader,
            'bearer' => self::Bearer,
            'basic' => self::Basic,
            default => self::None,
        };
    }
}
