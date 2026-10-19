<?php

declare(strict_types=1);

namespace N1co\EPay\Http;

/** One HTTP request as the SDK hands it to a Transport. */
final readonly class Request
{
    /**
     * @param 'GET'|'POST'          $method
     * @param non-empty-string      $url
     * @param array<string, string> $headers
     * @param float                 $timeout Seconds for the whole attempt.
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers,
        public ?string $body,
        public float $timeout,
    ) {}

    /**
     * Keeps the Authorization header out of var_dump(), print_r() and debuggers.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $headers = $this->headers;
        if (isset($headers['Authorization'])) {
            $headers['Authorization'] = 'Bearer [redacted]';
        }

        return ['method' => $this->method, 'url' => $this->url, 'headers' => $headers, 'body' => $this->body, 'timeout' => $this->timeout];
    }

    /** The request id the SDK sent (`X-Request-Id`). */
    public function requestId(): ?string
    {
        return $this->headers['X-Request-Id'] ?? null;
    }
}
