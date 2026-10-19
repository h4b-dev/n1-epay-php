<?php

declare(strict_types=1);

namespace N1co\EPay\Internal;

use N1co\EPay\Exception\AuthenticationException;
use N1co\EPay\Exception\ConflictException;
use N1co\EPay\Exception\InvalidRequestException;
use N1co\EPay\Exception\N1coException;
use N1co\EPay\Exception\NotFoundException;
use N1co\EPay\Exception\RateLimitException;
use N1co\EPay\Exception\ServiceException;
use N1co\EPay\Http\Response;

/**
 * Maps an API answer that is not a success to the exception class of its status.
 *
 * @internal
 */
final class ErrorFactory
{
    public static function fromResponse(Response $response, ?string $requestId, ?int $now = null): N1coException
    {
        $status = $response->status;
        $error = null;
        try {
            $body = json_decode($response->body, true, 64, JSON_THROW_ON_ERROR);
            if (\is_array($body) && isset($body['error']) && \is_array($body['error'])) {
                $error = $body['error'];
            }
        } catch (\JsonException) {
        }
        $code = \is_string($error['code'] ?? null) ? $error['code'] : 'http_' . $status;
        $message = \is_string($error['message'] ?? null)
            ? $error['message']
            : \sprintf('The n1co ePay API answered HTTP %d.', $status);
        $details = [];
        if (isset($error['details']) && \is_array($error['details']) && !array_is_list($error['details'])) {
            foreach ($error['details'] as $key => $value) {
                $details[(string) $key] = $value;
            }
        }
        $class = match (true) {
            $status === 400 => InvalidRequestException::class,
            $status === 401, $status === 403 => AuthenticationException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => ConflictException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 => ServiceException::class,
            $status >= 400 => InvalidRequestException::class,
            default => ServiceException::class, // 1xx and 3xx: redirects are never followed
        };

        return new $class(
            $message,
            $code,
            $details,
            $status,
            $requestId,
            self::retryAfter($response->header('retry-after'), $now ?? time()),
            $response->headers,
        );
    }

    /** Seconds from a `Retry-After` header in seconds or as an HTTP date; null otherwise. */
    public static function retryAfter(?string $value, int $now): ?int
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (preg_match('/^\d{1,9}$/', $value) === 1) {
            return (int) $value;
        }
        $format = 'D, d M Y H:i:s \G\M\T';
        $date = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));
        // createFromFormat() accepts overflowing fields (31 Feb, 25:00): only a date that formats
        // back to the same text is real.
        if ($date === false || $date->format($format) !== $value) {
            return null;
        }

        return max(0, $date->getTimestamp() - $now);
    }
}
