<?php

namespace App\Modules\Access\Contracts;

use RuntimeException;

/** A user attribute value cannot be sealed, opened or indexed (`access.attributes_unavailable`, HTTP 503): a key is missing or unusable. */
final class AttributesUnavailable extends RuntimeException {}
