<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/** 401 `invalid_api_key` (and 403): the secret key is missing, malformed or unknown. */
final class AuthenticationException extends N1coException {}
