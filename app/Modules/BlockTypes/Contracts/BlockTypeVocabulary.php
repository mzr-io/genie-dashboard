<?php

namespace App\Modules\BlockTypes\Contracts;

/** The closed vocabulary a package's `schema.json` may use. The registry rejects anything else; the generated TypeScript type mirrors it. */
final class BlockTypeVocabulary
{
    public const VALUE_TYPES = ['number', 'text', 'datetime', 'boolean'];

    public const CARDINALITIES = ['single'];

    public const FIELD_ROLES = ['label', 'value', 'dimension', 'measure', 'time', 'filter'];

    public const AGGREGATIONS = ['sum', 'avg', 'min', 'max', 'count', 'first', 'last'];

    public const HEIGHTS = ['small', 'medium', 'large'];
}
