<?php

declare(strict_types=1);

namespace N1co\EPay;

use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Internal\Reader;
use N1co\EPay\Model\Event;

/**
 * Verification of webhook deliveries (Standard Webhooks).
 *
 * ```php
 * $event = Webhook::constructEvent($rawBody, $headers, getenv('N1CO_WEBHOOK_SECRET'));
 * ```
 */
final class Webhook
{
    /** Largest accepted distance between `webhook-timestamp` and now, in seconds. */
    public const DEFAULT_TOLERANCE = 300;
    /** Largest accepted payload (1 MiB); a larger one is a WebhookSignatureException `invalid_payload`. */
    public const MAX_PAYLOAD_BYTES = 1024 * 1024;
    private const SECRET_PREFIX = 'whsec_';

    private function __construct() {}

    /**
     * Verifies a webhook delivery and returns its event (with `delivery` null).
     *
     * Pass the raw body exactly as received, the request headers (names in any case, values as
     * strings or arrays of strings, as PHP frameworks give them) and the store's `whsec_…`
     * secret. Throws WebhookSignatureException when a header is missing, the timestamp is more
     * than $tolerance seconds away from now (either direction), no `v1,` signature matches, or
     * the body is over 1 MiB or not an event envelope (`id`, `type`, `data`). A signed event whose object does
     * not fit its model is returned with `data->object` as an array (see EventData).
     *
     * @param array<array-key, mixed> $headers
     * @param int|null                $now     Current Unix time; for tests.
     *
     * @throws WebhookSignatureException
     * @throws \InvalidArgumentException when the secret or the tolerance is not usable
     */
    public static function constructEvent(
        string $payload,
        array $headers,
        #[\SensitiveParameter]
        string $secret,
        int $tolerance = self::DEFAULT_TOLERANCE,
        ?int $now = null,
    ): Event {
        if ($tolerance < 0) {
            throw new \InvalidArgumentException('tolerance must be a non-negative number of seconds.');
        }
        $key = self::signingKey($secret);
        if (\strlen($payload) > self::MAX_PAYLOAD_BYTES) {
            throw self::payloadTooLarge();
        }
        $id = self::required($headers, 'webhook-id');
        $timestamp = self::required($headers, 'webhook-timestamp');
        $signatures = self::required($headers, 'webhook-signature');

        if (preg_match('/^\d{1,15}$/', $timestamp) !== 1) {
            throw new WebhookSignatureException('The webhook-timestamp header is not a Unix timestamp in seconds.', 'invalid_timestamp');
        }
        $now ??= time();
        if (abs($now - (int) $timestamp) > $tolerance) {
            throw new WebhookSignatureException(
                \sprintf('The webhook-timestamp is more than %d seconds away from now.', $tolerance),
                'timestamp_out_of_tolerance',
            );
        }

        $expected = hash_hmac('sha256', $id . '.' . $timestamp . '.' . $payload, $key, true);
        $matches = false;
        foreach (explode(' ', (string) preg_replace('/\s+/', ' ', $signatures)) as $entry) {
            $parts = explode(',', $entry, 2);
            if (\count($parts) !== 2 || $parts[0] !== 'v1') {
                continue;
            }
            $candidate = base64_decode($parts[1], true);
            if ($candidate !== false && hash_equals($expected, $candidate)) {
                $matches = true; // keep comparing: the time taken does not depend on which entry matched
            }
        }
        if (!$matches) {
            throw new WebhookSignatureException('No v1 signature of the webhook-signature header matches the payload.', 'invalid_signature');
        }

        try {
            $envelope = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new WebhookSignatureException('The webhook payload is not JSON.', 'invalid_payload', previous: $e);
        }
        if (
            !\is_array($envelope) || ($envelope !== [] && array_is_list($envelope))
            || !\is_string($envelope['id'] ?? null) || !\is_string($envelope['type'] ?? null)
            || !\is_array($envelope['data'] ?? null) || ($envelope['data'] !== [] && array_is_list($envelope['data']))
        ) {
            throw new WebhookSignatureException('The webhook payload is not an n1co ePay event.', 'invalid_payload');
        }

        try {
            return Event::read(Reader::of($envelope, 'event'));
        } catch (\UnexpectedValueException) {
            // Signed by n1co but not as this SDK version expects: keep it readable.
            return Event::fromEnvelope($envelope);
        }
    }

    /**
     * Signs a payload like the API does, as the `webhook-signature` header value. For tests of
     * your webhook endpoint.
     */
    public static function generateSignature(string $payload, string $id, int $timestamp, #[\SensitiveParameter] string $secret): string
    {
        return 'v1,' . base64_encode(hash_hmac('sha256', $id . '.' . $timestamp . '.' . $payload, self::signingKey($secret), true));
    }

    /**
     * Whether $secret is `whsec_` followed by standard base64, as the `webhook_secret` option
     * requires.
     *
     * @internal
     */
    public static function isPrefixedSecret(#[\SensitiveParameter] string $secret): bool
    {
        return str_starts_with($secret, self::SECRET_PREFIX) && self::decodeSecret($secret) !== null;
    }

    /** @internal */
    public static function payloadTooLarge(): WebhookSignatureException
    {
        return new WebhookSignatureException(\sprintf('The webhook payload is over %d bytes.', self::MAX_PAYLOAD_BYTES), 'invalid_payload');
    }

    private static function signingKey(#[\SensitiveParameter] string $secret): string
    {
        return self::decodeSecret($secret)
            ?? throw new \InvalidArgumentException('The webhook secret (whsec_…) is required and must be base64 after its prefix.');
    }

    private static function decodeSecret(#[\SensitiveParameter] string $secret): ?string
    {
        $encoded = str_starts_with($secret, self::SECRET_PREFIX) ? substr($secret, \strlen(self::SECRET_PREFIX)) : $secret;
        $key = preg_match('#^[A-Za-z0-9+/]+={0,2}\z#', $encoded) === 1 ? base64_decode($encoded, true) : false;

        return $key === false || $key === '' ? null : $key;
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    private static function required(array $headers, string $name): string
    {
        $value = self::header($headers, $name);
        if ($value === null || $value === '') {
            throw new WebhookSignatureException(\sprintf('The webhook request has no %s header.', $name), 'missing_header');
        }

        return $value;
    }

    /**
     * A header by case-insensitive name; array values (one per header line) are joined with a
     * space, values of other types are ignored.
     *
     * @param array<array-key, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;
        if ($value === null) {
            foreach ($headers as $key => $candidate) {
                if (\is_string($key) && strtolower($key) === $name) {
                    $value = $candidate;
                    break;
                }
            }
        }
        if (\is_array($value)) {
            $value = implode(' ', array_filter($value, '\is_string'));
        }

        return \is_string($value) ? trim($value) : null;
    }
}
