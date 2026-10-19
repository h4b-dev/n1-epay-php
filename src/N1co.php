<?php

declare(strict_types=1);

namespace N1co\EPay;

use N1co\EPay\Http\CurlTransport;
use N1co\EPay\Http\Transport;
use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Service\CardSessions;
use N1co\EPay\Service\Events;
use N1co\EPay\Service\PaymentIntents;
use N1co\EPay\Service\Refunds;
use N1co\EPay\Service\Webhooks;

/**
 * Client of the n1co ePay API for your server, authenticated with the store's secret key.
 *
 * ```php
 * $n1co = new N1co(getenv('N1CO_SECRET_KEY'));
 * $intent = $n1co->paymentIntents->create(['amount' => 1250, 'currency' => 'USD', 'customer' => $customer]);
 * ```
 */
final class N1co
{
    /** Version of this SDK. */
    public const VERSION = '0.1.0';
    /** Version of the n1co ePay API contract (spec/openapi.yaml) this SDK was built against. */
    public const CONTRACT_VERSION = '0.10.0';
    /** Production API (the default). */
    public const DEFAULT_BASE_URL = 'https://api-epay.n1co.com';
    /** Development API. */
    public const DEV_BASE_URL = 'https://api-epay.dev.n1co.dev';
    public const DEFAULT_TIMEOUT = 30;
    public const DEFAULT_MAX_RETRIES = 2;

    private const OPTIONS = ['base_url', 'timeout', 'max_retries', 'app_info', 'transport', 'webhook_secret'];

    public readonly CardSessions $cardSessions;
    public readonly PaymentIntents $paymentIntents;
    public readonly Refunds $refunds;
    public readonly Events $events;
    public readonly Webhooks $webhooks;

    /**
     * @param string $secretKey The store's secret key (`sk_` and 32 characters). Server side only.
     * @param array{
     *     base_url?: string|null,
     *     timeout?: int|float,
     *     max_retries?: int,
     *     app_info?: array{name: string, version?: string, url?: string},
     *     transport?: Transport,
     *     webhook_secret?: string|null,
     * } $options `base_url` (null or omitted: production; `https` only, plain `http` only for
     *            localhost, 127.0.0.1 and [::1], any port),
     *            `timeout` per attempt in seconds (default 30; refunds->create() at least 90),
     *            `max_retries` of GET requests (default 2, 0 to 10; POST is never retried),
     *            `app_info` sent in the User-Agent, `transport` (default CurlTransport),
     *            `webhook_secret` (`whsec_…`) for `webhooks->constructEvent()`.
     *
     * @throws \InvalidArgumentException when the key or an option is not usable
     */
    public function __construct(#[\SensitiveParameter] string $secretKey, #[\SensitiveParameter] array $options = [])
    {
        if (preg_match('/^sk_[A-Za-z0-9_-]{32}$/', $secretKey) !== 1) {
            // Never echo the value: it may be a real key with a typo.
            throw new \InvalidArgumentException('A secret key (sk_ followed by 32 characters) is required. Never use the secret key in a browser; n1co.js uses client secrets.');
        }
        foreach (array_keys($options) as $name) {
            if (!\in_array($name, self::OPTIONS, true)) {
                throw new \InvalidArgumentException(\sprintf('Unknown option "%s"; known options: %s.', $name, implode(', ', self::OPTIONS)));
            }
        }
        $timeout = HttpClient::timeout($options['timeout'] ?? self::DEFAULT_TIMEOUT, 'timeout');
        $maxRetries = $options['max_retries'] ?? self::DEFAULT_MAX_RETRIES;
        if (!\is_int($maxRetries) || $maxRetries < 0 || $maxRetries > HttpClient::MAX_MAX_RETRIES) {
            throw new \InvalidArgumentException(\sprintf('max_retries must be an integer from 0 to %d.', HttpClient::MAX_MAX_RETRIES));
        }
        $transport = $options['transport'] ?? new CurlTransport();
        if (!$transport instanceof Transport) {
            throw new \InvalidArgumentException('transport must implement ' . Transport::class . '.');
        }
        $webhookSecret = $options['webhook_secret'] ?? null;
        if ($webhookSecret !== null && (!\is_string($webhookSecret) || !Webhook::isPrefixedSecret($webhookSecret))) {
            // Never echo the value.
            throw new \InvalidArgumentException('webhook_secret must be whsec_ followed by base64.');
        }

        $http = new HttpClient(
            $secretKey,
            self::baseUrl($options['base_url'] ?? self::DEFAULT_BASE_URL),
            $timeout,
            $maxRetries,
            self::userAgent($options['app_info'] ?? null),
            $transport,
        );
        $this->cardSessions = new CardSessions($http);
        $this->paymentIntents = new PaymentIntents($http);
        $this->refunds = new Refunds($http);
        $this->events = new Events($http);
        $this->webhooks = new Webhooks($http, $webhookSecret);
    }

    /**
     * Validates and normalises the base URL: `scheme://host[:port][/path]`, scheme and host in
     * lower case, no trailing slash. Errors never echo the value.
     */
    private static function baseUrl(mixed $value): string
    {
        if (!\is_string($value) || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            throw new \InvalidArgumentException('base_url must be a URL without spaces or control characters.');
        }
        $url = parse_url($value);
        if ($url === false || !isset($url['scheme'], $url['host']) || $url['host'] === '') {
            throw new \InvalidArgumentException('base_url is not a valid absolute URL.');
        }
        $scheme = strtolower($url['scheme']);
        $host = strtolower($url['host']);
        if ($scheme !== 'https' && !($scheme === 'http' && self::isLocalHost($host))) {
            throw new \InvalidArgumentException('base_url must use https (http only for localhost, 127.0.0.1 and [::1]).');
        }
        if (isset($url['query']) || isset($url['fragment']) || isset($url['user']) || isset($url['pass'])) {
            throw new \InvalidArgumentException('base_url must not have credentials, a query or a fragment.');
        }
        if ($host === '::1') {
            $host = '[::1]';
        }
        $port = isset($url['port']) ? ':' . $url['port'] : '';

        return $scheme . '://' . $host . $port . rtrim($url['path'] ?? '', '/');
    }

    /** Hosts where plain http is allowed (development against a local API). */
    public static function isLocalHost(string $host): bool
    {
        return \in_array(strtolower($host), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
    }

    private static function userAgent(mixed $appInfo): string
    {
        $agent = \sprintf('n1co-epay-php/%s php/%d.%d', self::VERSION, PHP_MAJOR_VERSION, PHP_MINOR_VERSION);
        if ($appInfo !== null) {
            if (!\is_array($appInfo) || !\is_string($appInfo['name'] ?? null) || trim($appInfo['name']) === '') {
                throw new \InvalidArgumentException('app_info.name must be a non-empty string.');
            }
            $agent .= ' ' . $appInfo['name'];
            if (\is_string($appInfo['version'] ?? null)) {
                $agent .= '/' . $appInfo['version'];
            }
            if (\is_string($appInfo['url'] ?? null)) {
                $agent .= ' (' . $appInfo['url'] . ')';
            }
        }

        return (string) preg_replace('/[^\x20-\x7e]/', '', $agent);
    }
}
