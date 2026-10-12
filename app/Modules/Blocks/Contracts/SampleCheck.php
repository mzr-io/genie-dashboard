<?php

namespace App\Modules\Blocks\Contracts;

/**
 * What the server found when it read a pasted sample (Story 3.8). A valid one carries its structure: the nodes (path, display path,
 * type, example), the table views, where the records are and the counts. A refused one carries why, never any of the text: `reason` is
 * `not_json` (with the line, the column and what is wrong there), `too_large` or `too_deep` (with the limit that was passed) or
 * `limit_not_configured` (with the setting that is unset), and nothing is stored in any of those cases.
 *
 * @phpstan-type Shape array{nodes: list<array<string, mixed>>, tables: array<int, array<string, mixed>>, records: array{path: string, display: string, count: int}|null, scalars: int, arrays: int, objects: int}
 */
final readonly class SampleCheck
{
    public const NOT_JSON = 'not_json';

    public const TOO_LARGE = 'too_large';

    public const TOO_DEEP = 'too_deep';

    public const LIMIT_NOT_CONFIGURED = 'limit_not_configured';

    /** @param  Shape|null  $shape */
    public function __construct(
        public bool $valid,
        public int $sizeBytes,
        public ?string $reason = null,
        public ?int $line = null,
        public ?int $column = null,
        public ?string $problem = null,
        public ?int $limit = null,
        public ?string $setting = null,
        public ?array $shape = null,
    ) {}
}
