<?php

namespace App\Modules\BlockTypes\Contracts;

/** The closed vocabulary a package's `schema.json` may use. The registry rejects anything else; the generated TypeScript type mirrors it. */
final class BlockTypeVocabulary
{
    public const VALUE_TYPES = ['number', 'text', 'datetime', 'boolean'];

    /** `single` is one value; `rows` is the value of every row, as a list (a chart's axis or one of its series). */
    public const CARDINALITIES = ['single', 'rows'];

    /** The most times one slot declaration may repeat (a chart's series). */
    public const MAX_REPEAT = 6;

    public const FIELD_ROLES = ['label', 'value', 'dimension', 'measure', 'time', 'filter'];

    public const AGGREGATIONS = ['sum', 'avg', 'min', 'max', 'count', 'first', 'last'];

    public const HEIGHTS = ['small', 'medium', 'large'];
}
