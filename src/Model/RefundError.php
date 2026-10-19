<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;

/**
 * Why the last send of a refund did not succeed.
 */
final readonly class RefundError
{
    private const KNOWN = ['code', 'message'];

    /**
     * @param string $code One of RefundErrorCode::ALL, or a value added to the API later.
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     */
    public function __construct(
        public string $code,
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
        return self::read(Reader::of($data, 'last_error'));
    }

    /** @internal */
    public static function read(Reader $r): self
    {
        return new self(
            code: $r->string('code'),
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
            'code' => $this->code,
            'message' => $this->message,
        ];

        return $data + $this->extra;
    }
}
