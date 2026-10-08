<?php

namespace App\Platform\EditLock;

use RuntimeException;

/** The resource is not registered, or does not exist in the Workspace. */
final class EditLockResourceMissing extends RuntimeException {}
