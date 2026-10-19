<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * The `data.object` of a `webhook.test` event.
 */
final readonly class WebhookTestObject
{
    private const KNOWN = ['message'];

    /**
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $message,
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
        return self::read(Reader::of($data, 'object'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            message: $r->string('message'),
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
            'message' => $this->message,
        ];

        return $data + $this->extra;
    }
}
