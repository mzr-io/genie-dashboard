<?php

namespace App\Modules\Results\Contracts;

/**
 * A Block Result's state (Story 4.2; AR-33). `precedence()` is the order in which states override each other when more than one applies:
 * access_removed > unpublished > error > pending > unavailable > empty > ok. `partial` is derived (some slots unavailable, some not) and `loading`
 * is a client state that is never stored, so neither is a case.
 */
enum ResultState: string
{
    case AccessRemoved = 'access_removed';
    case Unpublished = 'unpublished';
    case Error = 'error';
    case Pending = 'pending';
    case Unavailable = 'unavailable';
    case Empty = 'empty';
    case Ok = 'ok';

    /** @return list<self> strongest first */
    public static function precedence(): array
    {
        return self::cases();
    }

    /** The state that wins among those that apply. */
    public static function strongest(self ...$states): self
    {
        foreach (self::precedence() as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return self::Ok;
    }
}
