<?php

declare(strict_types=1);

namespace N1co\EPay\Internal;

use N1co\EPay\Exception\ConnectionException;
use N1co\EPay\Exception\N1coException;
use N1co\EPay\Exception\ServiceException;
use N1co\EPay\Http\Request;
use N1co\EPay\Http\Transport;
use N1co\EPay\ResponseInfo;

/**
 * Sends API requests: headers, timeouts, error mapping and GET-only retries.
 *
 * @internal
 */
final class HttpClient
{
    /** Longest `Retry-After` the SDK waits for by itself; a longer one is left to the caller. */
    public const MAX_RETRY_AFTER_SECONDS = 30;
    public const MAX_MAX_RETRIES = 10;
    private const INITIAL_BACKOFF_MS = 500;
    private const MAX_BACKOFF_MS = 4000;
    private const RETRY_AFTER_JITTER_MS = 250;
    /** Request fields that are JSON objects (an empty one must not become `[]`). */
    private const OBJECT_FIELDS = ['metadata', 'customer', 'billing'];

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /** @var \Closure(): float */
    private readonly \Closure $random;

    /**
     * @param float                   $timeout Seconds per attempt.
     * @param (\Closure(float): void)|null $sleep  Waits the given milliseconds (test seam).
     * @param (\Closure(): float)|null     $random A number in [0, 1) (test seam).
     */
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly float $timeout,
        private readonly int $maxRetries,
        private readonly string $userAgent,
        private readonly Transport $transport,
        ?\Closure $sleep = null,
        ?\Closure $random = null,
    ) {
        $this->sleep = $sleep ?? static function (float $ms): void {
            usleep((int) round($ms * 1000));
        };
        $this->random = $random ?? static fn(): float => random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX;
    }

    /**
     * Sends a request and maps its JSON object answer with $map.
     *
     * @template T
     *
     * @param 'GET'|'POST'                           $method
     * @param non-empty-string                       $path
     * @param array<string, string|int|null>         $query
     * @param array<array-key, mixed>|null           $body
     * @param array<array-key, mixed>                $options Per-call options: `timeout` in seconds.
     * @param \Closure(Reader, ResponseInfo): T     $map
     *
     * @return T
     */
    public function request(
        string $method,
        string $path,
        \Closure $map,
        array $query = [],
        ?array $body = null,
        array $options = [],
        float $minTimeout = 0.0,
    ): mixed {
        $timeout = self::callTimeout($options) ?? max($this->timeout, $minTimeout);
        $url = $this->baseUrl . $path . self::queryString($query);
        $payload = null;
        if ($body !== null) {
            try {
                $payload = json_encode(self::objects($body), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('The request parameters cannot be encoded as JSON: ' . $e->getMessage(), 0, $e);
            }
        }
        $maxRetries = $method === 'GET' ? $this->maxRetries : 0;

        for ($retry = 0; ; ++$retry) {
            $requestId = self::uuid();
            $request = new Request($method, $url, [
                'Authorization' => 'Bearer ' . $this->secretKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'User-Agent' => $this->userAgent,
                'X-Request-Id' => $requestId,
            ], $payload, $timeout);

            try {
                $response = $this->transport->send($request);
                $echoed = $response->header('x-request-id') ?? $requestId;
                if ($response->status >= 200 && $response->status < 300) {
                    return self::decode($response->status, $response->headers, $response->body, $echoed, $map);
                }
                $error = ErrorFactory::fromResponse($response, $echoed);
            } catch (ConnectionException $e) {
                $error = $e;
            }
            if ($retry >= $maxRetries || !self::isRetryable($error)) {
                throw $error;
            }
            ($this->sleep)($this->retryDelayMs($retry, $error->retryAfter()));
        }
    }

    /**
     * Keeps the secret key out of var_dump(), print_r() and debuggers.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'secretKey' => 'sk_[redacted]',
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'userAgent' => $this->userAgent,
            'transport' => $this->transport::class,
        ];
    }

    /**
     * Request bodies are JSON objects: an empty body, and an empty `metadata`, `customer` or
     * `billing`, are sent as `{}`, never `[]`.
     *
     * @param array<array-key, mixed> $body
     */
    private static function objects(array $body): object
    {
        $object = new \stdClass();
        foreach ($body as $key => $value) {
            $object->{$key} = \in_array($key, self::OBJECT_FIELDS, true) && $value === [] ? new \stdClass() : $value;
        }

        return $object;
    }

    /** Whether a failed GET may be sent again. POST requests are never retried. */
    public static function isRetryable(N1coException $error): bool
    {
        if ($error instanceof ConnectionException) {
            return true;
        }
        $status = $error->status() ?? 0;
        if ($status === 429 || $status >= 500) {
            return $error->retryAfter() === null || $error->retryAfter() <= self::MAX_RETRY_AFTER_SECONDS;
        }

        return false;
    }

    /**
     * Delay before retry number $retry (0-based): the server's `Retry-After` plus up to 250 ms of
     * jitter, or exponential backoff with equal jitter (250-500 ms, 500-1000 ms, … up to 4 s).
     */
    public function retryDelayMs(int $retry, ?int $retryAfter): float
    {
        $random = ($this->random)();
        if ($retryAfter !== null) {
            return $retryAfter * 1000 + $random * self::RETRY_AFTER_JITTER_MS;
        }
        $ceiling = min(self::MAX_BACKOFF_MS, self::INITIAL_BACKOFF_MS * 2 ** min($retry, 16));

        return $ceiling / 2 + $random * $ceiling / 2;
    }

    /**
     * Validates a timeout in seconds.
     */
    public static function timeout(mixed $value, string $name): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value) || $value <= 0) {
            throw new \InvalidArgumentException(\sprintf('%s must be a positive number of seconds.', $name));
        }

        return (float) $value;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function callTimeout(array $options): ?float
    {
        foreach (array_keys($options) as $key) {
            if ($key !== 'timeout') {
                throw new \InvalidArgumentException(\sprintf('Unknown request option "%s"; the only one is "timeout".', $key));
            }
        }

        return \array_key_exists('timeout', $options) ? self::timeout($options['timeout'], 'timeout') : null;
    }

    /**
     * @template T
     *
     * @param array<string, string>             $headers
     * @param \Closure(Reader, ResponseInfo): T $map
     *
     * @return T
     */
    private static function decode(int $status, array $headers, #[\SensitiveParameter] string $body, string $requestId, \Closure $map): mixed
    {
        $info = new ResponseInfo($requestId, $status, $headers);
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

            return $map(Reader::of($data, 'response'), $info);
        } catch (\JsonException|\UnexpectedValueException $e) {
            throw new ServiceException(
                \sprintf('The n1co ePay API answered HTTP %d with a body the SDK cannot read (%s).', $status, $e instanceof \JsonException ? 'not JSON' : $e->getMessage()),
                'invalid_response',
                status: $status,
                requestId: $requestId,
                headers: $headers,
                previous: $e,
            );
        }
    }

    /**
     * @param array<string, string|int|null> $query
     */
    private static function queryString(array $query): string
    {
        $query = array_filter($query, static fn($value): bool => $value !== null);
        $encoded = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $encoded === '' ? '' : '?' . $encoded;
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
