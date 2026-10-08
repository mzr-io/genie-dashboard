<?php

namespace App\Platform\EditLock;

use RuntimeException;

/** Another caller held the resource's lock for too long: transient, the caller retries. */
final class EditLockBusy extends RuntimeException {}
