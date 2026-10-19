<?php

declare(strict_types=1);

namespace N1co\EPay\Http;

/** One HTTP response as a Transport returns it. */
final readonly class Response
{
    /** @var array<string, string> */
    public array $headers;

    /** RFC 9110 token: what a header name may be. */
    private const TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/';

    /**
     * @param array<array-key, mixed> $headers Header names in any case, stored in lower case;
     *                                         names that are not tokens and values that are
     *                                         not strings are dropped.
     */
    public function __construct(
        public int $status,
        array $headers,
        public string $body,
    ) {
        $lower = [];
        foreach ($headers as $name => $value) {
            $name = strtolower((string) $name);
            if (\is_string($value) && preg_match(self::TOKEN, $name) === 1) {
                $lower[$name] = $value;
            }
        }
        $this->headers = $lower;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
