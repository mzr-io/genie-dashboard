<?php

namespace App\Modules\Connector\Contracts;

use RuntimeException;

/** A connection or transfer failure. The message carries the cURL error number only, never an address. */
final class EgressTransportFailed extends RuntimeException {}
