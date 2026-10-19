<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * The webhook delivery state of an event.
 */
final readonly class EventDelivery
{
    private const KNOWN = ['status', 'attempts', 'last_attempt_at', 'last_status_code', 'next_attempt_at', 'delivered_at'];

    /**
     * @param string $status One of DeliveryStatus::ALL, or a value added to the API later.
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $status,
        public int $attempts,
        public ?string $last_attempt_at,
        public ?int $last_status_code,
        public ?string $next_attempt_at,
        public ?string $delivered_at,
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
        return self::read(Reader::of($data, 'delivery'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            status: $r->string('status'),
            attempts: $r->int('attempts'),
            last_attempt_at: $r->nullableString('last_attempt_at'),
            last_status_code: $r->nullableInt('last_status_code'),
            next_attempt_at: $r->nullableString('next_attempt_at'),
            delivered_at: $r->nullableString('delivered_at'),
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
            'status' => $this->status,
            'attempts' => $this->attempts,
            'last_attempt_at' => $this->last_attempt_at,
            'last_status_code' => $this->last_status_code,
            'next_attempt_at' => $this->next_attempt_at,
            'delivered_at' => $this->delivered_at,
        ];

        return $data + $this->extra;
    }
}
