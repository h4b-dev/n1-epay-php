<?php

declare(strict_types=1);

namespace N1co\EPay\Internal;

use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Webhook;

/**
 * The current request as a webhook delivery: its raw body, read in chunks up to the payload cap,
 * and its headers.
 *
 * @internal
 */
final class WebhookRequest
{
    private const CHUNK_BYTES = 8192;

    /**
     * @param resource|string              $input  The body stream, or a stream URL to open.
     * @param array<array-key, mixed>|null $server `$_SERVER`-style entries (`HTTP_*`); null reads
     *                                             getallheaders() when available, else `$_SERVER`.
     */
    public function __construct(
        private readonly mixed $input = 'php://input',
        private readonly ?array $server = null,
    ) {}

    /**
     * @throws WebhookSignatureException `invalid_payload` as soon as the body exceeds the cap
     * @throws \RuntimeException         when the body cannot be read
     */
    public function body(): string
    {
        $stream = \is_string($this->input) ? @fopen($this->input, 'rb') : $this->input;
        if (!\is_resource($stream)) {
            throw new \RuntimeException('Cannot read the webhook request body.');
        }
        try {
            $body = '';
            while (!feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new \RuntimeException('Cannot read the webhook request body.');
                }
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
                if (\strlen($body) > Webhook::MAX_PAYLOAD_BYTES) {
                    throw Webhook::payloadTooLarge();
                }
            }

            return $body;
        } finally {
            if (\is_string($this->input)) {
                fclose($stream);
            }
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    public function headers(): array
    {
        $server = $this->server;
        if ($server === null) {
            if (\function_exists('getallheaders')) {
                $headers = getallheaders();
                if ($headers !== []) {
                    return $headers;
                }
            }
            $server = $_SERVER;
        }
        $headers = [];
        foreach ($server as $key => $value) {
            if (\is_string($key) && str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
            }
        }

        return $headers;
    }
}
