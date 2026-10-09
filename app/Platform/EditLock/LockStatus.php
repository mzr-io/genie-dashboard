<?php

namespace App\Platform\EditLock;

/** What an edit-lock call answers. */
enum LockStatus: string
{
    /** The soft lock is off (`edit_lock_ttl` unset). */
    case Disabled = 'disabled';
    /** The caller holds the lock. */
    case Granted = 'granted';
    /** Someone else holds it (read-only for the caller). */
    case Held = 'held';
    /** Nobody holds the lock: the caller acquires it (a take-over poll never grants). */
    case Free = 'free';
    /** A take-over was requested and the holder has not flushed yet. */
    case Waiting = 'waiting';
    /** The caller's lock is gone with no notice (expired, released, or replaced by the same person's other tab). */
    case Lost = 'lost';
    /** Someone took the caller's lock over; `flushAcknowledged` says whether its flush was confirmed first. */
    case TakenOver = 'taken_over';
}
