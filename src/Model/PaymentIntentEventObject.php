<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * A payment intent as sent in `payment_intent.*` events: without `next_action`, `billing` and `page_origins`.
 */
final readonly class PaymentIntentEventObject
{
    private const KNOWN = ['id', 'object', 'status', 'amount', 'currency', 'description', 'reference', 'metadata', 'card', 'payment_method', 'cvc_required', 'last_error', 'attempts_remaining', 'authorization_code', 'order_id', 'cancellation_reason', 'refund', 'created_at', 'updated_at', 'succeeded_at', 'canceled_at', 'expires_at'];

    /**
     * @param string $status One of PaymentIntentStatus::ALL, or a value added to the API later.
     * @param array<string, string> $metadata
     * @param ?string $cancellation_reason One of CancellationReason::ALL, or a value added to the API later.
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $id,
        public string $object,
        public string $status,
        public int $amount,
        public string $currency,
        public ?string $description,
        public ?string $reference,
        public array $metadata,
        public ?string $card,
        public ?PaymentMethodSummary $payment_method,
        public bool $cvc_required,
        public ?PaymentIntentError $last_error,
        public int $attempts_remaining,
        public ?string $authorization_code,
        public ?string $order_id,
        public ?string $cancellation_reason,
        public ?string $refund,
        public string $created_at,
        public string $updated_at,
        public ?string $succeeded_at,
        public ?string $canceled_at,
        public string $expires_at,
        public array $extra = [],
    ) {}

    /**
     * Builds the object from its JSON form (decoded as an array).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or has another type
     */
    public static function fromArray(array $data): self
    {
        return self::read(Reader::of($data, 'payment_intent'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            id: $r->string('id'),
            object: $r->string('object'),
            status: $r->string('status'),
            amount: $r->int('amount'),
            currency: $r->string('currency'),
            description: $r->nullableString('description'),
            reference: $r->nullableString('reference'),
            metadata: $r->stringMap('metadata'),
            card: $r->nullableString('card'),
            payment_method: ($o = $r->nullableObject('payment_method')) === null ? null : PaymentMethodSummary::read($o),
            cvc_required: $r->bool('cvc_required'),
            last_error: ($o = $r->nullableObject('last_error')) === null ? null : PaymentIntentError::read($o),
            attempts_remaining: $r->int('attempts_remaining'),
            authorization_code: $r->nullableString('authorization_code'),
            order_id: $r->nullableString('order_id'),
            cancellation_reason: $r->nullableString('cancellation_reason'),
            refund: $r->nullableString('refund'),
            created_at: $r->string('created_at'),
            updated_at: $r->string('updated_at'),
            succeeded_at: $r->nullableString('succeeded_at'),
            canceled_at: $r->nullableString('canceled_at'),
            expires_at: $r->string('expires_at'),
            extra: $r->extra(self::KNOWN),
        );
    }

    /**
     * The object in its JSON form, unknown fields included.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'object' => $this->object,
            'status' => $this->status,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'reference' => $this->reference,
            'metadata' => $this->metadata,
            'card' => $this->card,
            'payment_method' => $this->payment_method?->toArray(),
            'cvc_required' => $this->cvc_required,
            'last_error' => $this->last_error?->toArray(),
            'attempts_remaining' => $this->attempts_remaining,
            'authorization_code' => $this->authorization_code,
            'order_id' => $this->order_id,
            'cancellation_reason' => $this->cancellation_reason,
            'refund' => $this->refund,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'succeeded_at' => $this->succeeded_at,
            'canceled_at' => $this->canceled_at,
            'expires_at' => $this->expires_at,
        ];

        return $data + $this->extra;
    }
}
