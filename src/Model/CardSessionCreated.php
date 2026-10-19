<?php

declare(strict_types=1);

namespace N1co\EPay\Model;

use N1co\EPay\Internal\Reader;
use N1co\EPay\ResponseInfo;

/**
 * The answer of cardSessions->create(): the session with its `client_secret`, shown only once.
 */
final readonly class CardSessionCreated
{
    private const KNOWN = ['id', 'client_secret', 'mode', 'card', 'status', 'attempts_remaining', 'page_origins', 'expires_at'];

    /**
     * @param string $mode One of CardSessionMode::ALL, or a value added to the API later.
     * @param string $status One of CardSessionStatus::ALL, or a value added to the API later.
     * @param list<string>|null $page_origins
     * @param array<string, mixed> $extra Fields this SDK version does not know, kept as received.
     * @param ResponseInfo|null $lastResponse The HTTP response behind this object, when it came from the API.
     */
    public function __construct(
        public string $id,
        public string $client_secret,
        public string $mode,
        public ?string $card,
        public string $status,
        public int $attempts_remaining,
        public ?array $page_origins,
        public string $expires_at,
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
        return self::read(Reader::of($data, 'card_session'), $lastResponse);
    }

    /** @internal */
    public static function read(Reader $r, ?ResponseInfo $lastResponse = null): self
    {
        return new self(
            id: $r->string('id'),
            client_secret: $r->string('client_secret'),
            mode: $r->string('mode'),
            card: $r->nullableString('card'),
            status: $r->string('status'),
            attempts_remaining: $r->int('attempts_remaining'),
            page_origins: $r->nullableStringList('page_origins'),
            expires_at: $r->string('expires_at'),
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
            'client_secret' => $this->client_secret,
            'mode' => $this->mode,
            'card' => $this->card,
            'status' => $this->status,
            'attempts_remaining' => $this->attempts_remaining,
            'page_origins' => $this->page_origins,
            'expires_at' => $this->expires_at,
        ];

        return $data + $this->extra;
    }
}
