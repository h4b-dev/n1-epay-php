<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/** 404: no such object in the store, for example `payment_intent_not_found`. */
final class NotFoundException extends N1coException {}
