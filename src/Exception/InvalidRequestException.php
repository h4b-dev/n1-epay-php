<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/** 400 (and other 4xx without a class of their own): the request is invalid. See details(). */
final class InvalidRequestException extends N1coException {}
