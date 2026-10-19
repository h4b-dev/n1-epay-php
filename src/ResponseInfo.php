<?php

declare(strict_types=1);

namespace N1co\EPay;

/** What the SDK knows about the HTTP response behind a result (`$result->lastResponse`). */
final readonly class ResponseInfo
{
    /**
     * @param array<string, string> $headers Names in lower case.
     */
    public function __construct(
        /** The `X-Request-Id` echoed by the API (or the one the SDK sent). Quote it when contacting n1co. */
        public ?string $requestId,
        public int $status,
        public array $headers,
    ) {}
}
