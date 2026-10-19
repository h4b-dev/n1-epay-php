<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/**
 * Webhook::constructEvent() rejected a delivery. Answer it with a 400 and do not process it.
 * code(): `missing_header`, `invalid_timestamp`, `timestamp_out_of_tolerance`,
 * `invalid_signature` or `invalid_payload`.
 */
final class WebhookSignatureException extends N1coException {}
