<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;
use N1co\EPay\ResponseInfo;

/**
 * A refund: the full reversal of a succeeded payment intent.
 */
final readonly class Refund
{
    private const KNOWN = ['id', 'object', 'payment_intent', 'amount', 'currency', 'status', 'reason', 'metadata', 'last_error', 'attempts', 'created_at', 'updated_at', 'succeeded_at', 'failed_at'];

    /**
     * @param string $status One of RefundStatus::ALL, or a value added to the API later.
     * @param array<string, string> $metadata
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     * @param ResponseInfo|null $lastResponse The HTTP response behind this object, when it came from the API.
     */
    public function __construct(
        public string $id,
        public string $object,
        public string $payment_intent,
        public int $amount,
        public string $currency,
        public string $status,
        public ?string $reason,
        public array $metadata,
        public ?RefundError $last_error,
        public int $attempts,
        public string $created_at,
        public string $updated_at,
        public ?string $succeeded_at,
        public ?string $failed_at,
        public array $extra = [],
        public ?ResponseInfo $lastResponse = null,
    ) {}

    /**
     * Builds the object from its JSON form (decoded as an array).
     *
     * @param array<array-key, mixed> $data
     *
     * @throws \UnexpectedValueException when a field is missing or has another type
     */
    public static function fromArray(array $data, ?ResponseInfo $lastResponse = null): self
    {
        return self::read(Reader::of($data, 'refund'), $lastResponse);
    }

    /** @internal */
    public static function read(Reader $r, ?ResponseInfo $lastResponse = null): self
    {
        return new self(
            id: $r->string('id'),
            object: $r->string('object'),
            payment_intent: $r->string('payment_intent'),
            amount: $r->int('amount'),
            currency: $r->string('currency'),
            status: $r->string('status'),
            reason: $r->nullableString('reason'),
            metadata: $r->stringMap('metadata'),
            last_error: ($o = $r->nullableObject('last_error')) === null ? null : RefundError::read($o),
            attempts: $r->int('attempts'),
            created_at: $r->string('created_at'),
            updated_at: $r->string('updated_at'),
            succeeded_at: $r->nullableString('succeeded_at'),
            failed_at: $r->nullableString('failed_at'),
            extra: $r->extra(self::KNOWN),
            lastResponse: $lastResponse,
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
            'payment_intent' => $this->payment_intent,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'last_error' => $this->last_error?->toArray(),
            'attempts' => $this->attempts,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'succeeded_at' => $this->succeeded_at,
            'failed_at' => $this->failed_at,
        ];

        return $data + $this->extra;
    }
}
