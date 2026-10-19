<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Event types.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is.
 */
final class EventType
{
    public const CARD_CREATED = 'card.created';
    public const CARD_UPDATED = 'card.updated';
    public const PAYMENT_INTENT_SUCCEEDED = 'payment_intent.succeeded';
    public const PAYMENT_INTENT_PAYMENT_FAILED = 'payment_intent.payment_failed';
    public const PAYMENT_INTENT_CANCELED = 'payment_intent.canceled';
    public const REFUND_SUCCEEDED = 'refund.succeeded';
    public const REFUND_FAILED = 'refund.failed';
    public const WEBHOOK_TEST = 'webhook.test';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::CARD_CREATED,
        self::CARD_UPDATED,
        self::PAYMENT_INTENT_SUCCEEDED,
        self::PAYMENT_INTENT_PAYMENT_FAILED,
        self::PAYMENT_INTENT_CANCELED,
        self::REFUND_SUCCEEDED,
        self::REFUND_FAILED,
        self::WEBHOOK_TEST,
    ];

    private function __construct() {}
}
