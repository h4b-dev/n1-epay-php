<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/** 429 `rate_limited`. retryAfter() says when to try again. */
final class RateLimitException extends N1coException {}
