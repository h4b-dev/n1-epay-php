<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/**
 * No response was received: network failure or timeout (code() `connection_error` or `timeout`).
 * The outcome of a POST is unknown: read the object or wait for its webhook before trying again.
 */
final class ConnectionException extends N1coException {}
