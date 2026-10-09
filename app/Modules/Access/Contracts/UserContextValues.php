<?php

namespace App\Modules\Access\Contracts;

/**
 * What {@see UserContext::resolve()} produced: the value of each binding that could be resolved, and the names of the ones that
 * could not. A missing name is a user attribute key id, or the binding kind `user_group` when the member is in no group or in
 * several; it is never a value. Callers fail closed when {@see self::$missing} is not empty.
 */
final readonly class UserContextValues
{
    /**
     * @param  array<string, string>  $values  reference => value; sensitive, for the caller's process only
     * @param  list<string>  $missing  sorted, without duplicates
     */
    public function __construct(
        #[\SensitiveParameter] public array $values,
        public array $missing,
    ) {}

    public function complete(): bool
    {
        return $this->missing === [];
    }
}
