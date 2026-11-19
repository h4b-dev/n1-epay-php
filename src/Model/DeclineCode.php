<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

/**
 * Decline codes: why n1co declined a charge, in the `decline_code` of a `last_error` whose code
 * is `card_declined`. ePay's own names, whatever processor answered.
 *
 * Plain string constants, not an enum: the API may add values, and a value this SDK version
 * does not know is passed through as it is. Treat one as a plain decline.
 */
final class DeclineCode
{
    /** The card has insufficient funds. */
    public const INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';

    /** The card's limit or its daily transaction limit was exceeded. */
    public const LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

    /** The card is expired or the expiration date is wrong. */
    public const EXPIRED_CARD = 'EXPIRED_CARD';

    /** The security code is wrong. */
    public const INCORRECT_CVC = 'INCORRECT_CVC';

    /** The issuer does not know the card number. */
    public const INVALID_NUMBER = 'INVALID_NUMBER';

    /** The issuer refused the amount. */
    public const INVALID_AMOUNT = 'INVALID_AMOUNT';

    /** The card is not active or not enabled for online payments. */
    public const CARD_NOT_ACTIVE = 'CARD_NOT_ACTIVE';

    /** The card does not allow this payment. */
    public const TRANSACTION_NOT_ALLOWED = 'TRANSACTION_NOT_ALLOWED';

    /** The card is reported lost or stolen. Never show it to the buyer. */
    public const LOST_OR_STOLEN_CARD = 'LOST_OR_STOLEN_CARD';

    /** The issuer declined without more detail; the cardholder should call it. */
    public const CALL_ISSUER = 'CALL_ISSUER';

    /** The issuer did not answer; retry later. */
    public const ISSUER_UNAVAILABLE = 'ISSUER_UNAVAILABLE';

    /** The charge did not pass the fraud checks. */
    public const FRAUD_PREVENT = 'FRAUD_PREVENT';

    /** Every value this SDK version knows. */
    public const ALL = [
        self::INSUFFICIENT_FUNDS,
        self::LIMIT_EXCEEDED,
        self::EXPIRED_CARD,
        self::INCORRECT_CVC,
        self::INVALID_NUMBER,
        self::INVALID_AMOUNT,
        self::CARD_NOT_ACTIVE,
        self::TRANSACTION_NOT_ALLOWED,
        self::LOST_OR_STOLEN_CARD,
        self::CALL_ISSUER,
        self::ISSUER_UNAVAILABLE,
        self::FRAUD_PREVENT,
    ];

    private function __construct() {}
}
