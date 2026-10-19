<?php

declare(strict_types=1);

namespace N1co\EPay\Exception;

/**
 * Base class of every exception the SDK throws for API, network and webhook failures.
 *
 * Branch on code() (the API's stable error code) and details() (for example `field` and `code`
 * of a 400 `invalid_request`), never on the message. getCode() is the HTTP status, or 0.
 */
abstract class N1coException extends \RuntimeException
{
    /**
     * @param array<string, mixed>  $details
     * @param array<string, string> $headers Response headers, names in lower case.
     */
    final public function __construct(
        string $message,
        private readonly string $apiCode,
        private readonly array $details = [],
        private readonly ?int $status = null,
        private readonly ?string $requestId = null,
        private readonly ?int $retryAfter = null,
        private readonly array $headers = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status ?? 0, $previous);
    }

    /** The API's stable error code, for example `invalid_request` or `payment_intent_not_found`. */
    public function code(): string
    {
        return $this->apiCode;
    }

    /**
     * The API's `error.details` (for example `field` and `code`); empty when it sent none.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /** HTTP status of the response; null when no response was received. */
    public function status(): ?int
    {
        return $this->status;
    }

    /** The `X-Request-Id` of the request. Quote it when contacting n1co. */
    public function requestId(): ?string
    {
        return $this->requestId;
    }

    /** Seconds from the `Retry-After` header, when the API sent one. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Response headers, names in lower case; empty when no response was received.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
