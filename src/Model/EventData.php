<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * The `data` of an event. `object` is typed by the event type: Card for `card.created` and
 * `card.updated`, PaymentIntentEventObject for `payment_intent.*`, Refund for `refund.*` and
 * WebhookTestObject for `webhook.test` (the exact types in EventType::ALL). For a type this SDK
 * version does not know, or an object that does not fit its model, `object` is the decoded
 * array. `raw` is always the decoded `data.object`.
 */
final readonly class EventData
{
    private const KNOWN = ['object'];

    /**
     * @param Card|PaymentIntentEventObject|Refund|WebhookTestObject|array<string, mixed> $object
     * @param array<string, mixed>                                                         $raw    The decoded `data.object`, as received.
     * @param array<string, mixed>                                                         $extra  Fields this SDK version does not know.
     */
    public function __construct(
        public Card|PaymentIntentEventObject|Refund|WebhookTestObject|array $object,
        public array $raw,
        public array $extra = [],
    ) {}

    /**
     * @param array<array-key, mixed> $data
     *
     * @throws \UnexpectedValueException when `data` or `data.object` is not an object
     */
    public static function fromArray(array $data, string $type): self
    {
        return self::read(Reader::of($data, 'data'), $type);
    }

    /** @internal */
    public static function read(Reader $r, string $type): self
    {
        $object = $r->object('object');
        $raw = $object->extra([]);
        try {
            $typed = match ($type) {
                EventType::CARD_CREATED, EventType::CARD_UPDATED => Card::read($object),
                EventType::PAYMENT_INTENT_SUCCEEDED, EventType::PAYMENT_INTENT_PAYMENT_FAILED, EventType::PAYMENT_INTENT_CANCELED => PaymentIntentEventObject::read($object),
                EventType::REFUND_SUCCEEDED, EventType::REFUND_FAILED => Refund::read($object),
                EventType::WEBHOOK_TEST => WebhookTestObject::read($object),
                default => $raw,
            };
        } catch (\UnexpectedValueException) {
            $typed = $raw; // the object drifted from its model: keep it readable as an array
        }

        return new self($typed, $raw, $r->extra(self::KNOWN));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['object' => \is_array($this->object) ? $this->object : $this->object->toArray()] + $this->extra;
    }
}
