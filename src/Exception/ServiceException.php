<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/** 5xx, or an answer the SDK could not understand, for example `n1co_api_unavailable` (503). */
final class ServiceException extends N1coException {}
