<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** The sample is a Fetch as user response and its reader no longer holds `data.preview_as_user` (HTTP 403). */
final class SamplePreviewDenied extends RuntimeException {}
