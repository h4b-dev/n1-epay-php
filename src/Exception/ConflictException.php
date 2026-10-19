<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/** 409: the object's state does not allow the request, for example `refund_exists`. */
final class ConflictException extends N1coException {}
