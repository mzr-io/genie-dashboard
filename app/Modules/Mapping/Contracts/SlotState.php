<?php

namespace App\Modules\Mapping\Contracts;

/** Where a Slot stands in the auto-map checklist (Story 3.12a). `auto_high`, `confirmed` and `overridden` count as confirmed. */
enum SlotState: string
{
    case AutoHigh = 'auto_high';
    case AutoMedium = 'auto_medium';
    case AutoLow = 'auto_low';
    case Confirmed = 'confirmed';
    case Overridden = 'overridden';
    case OptionalEmpty = 'optional_empty';
    case RequiredMissing = 'required_missing';
    case TypeMismatch = 'type_mismatch';

    public function isAuto(): bool
    {
        return in_array($this, [self::AutoHigh, self::AutoMedium, self::AutoLow], true);
    }

    /** A state that maps a field into the plan. */
    public function isMapped(): bool
    {
        return $this->isAuto() || $this === self::Confirmed || $this === self::Overridden;
    }

    /** The Admin's own decisions, which a re-run never changes and which are stored in `config.slot_map`. */
    public function isDecision(): bool
    {
        return $this === self::Confirmed || $this === self::Overridden;
    }
}
