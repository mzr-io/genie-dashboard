<?php

namespace App\Modules\Mapping\Contracts;

/** The six roles a sample field can play (Story 3.11a). */
enum FieldRole: string
{
    case Label = 'label';
    case Value = 'value';
    case Dimension = 'dimension';
    case Measure = 'measure';
    case Time = 'time';
    case Filter = 'filter';
}
