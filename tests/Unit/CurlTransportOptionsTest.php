<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Http\CurlTransport;
use N1co\EPay\Http\Request;
use PHPUnit\Framework\TestCase;

/** Pins the curl options that keep the secret key safe. */
final class CurlTransportOptionsTest extends TestCase
{
    /**
     * @param non-empty-string $url
     *
     * @return array<int, mixed>
     */
    private static function options(string $url, string $method = 'GET', float $timeout = 30.0): array
    {
        $request = new Request($method === 'GET' ? 'GET' : 'POST', $url, ['Authorization' => 'Bearer x', 'Accept' => 'application/json'], $method === 'GET' ? null : '{}', $timeout);

        return CurlTransport::options($request, static fn(\CurlHandle $h, string $line): int => \strlen($line));
    }

    public function testVerifiesTlsAndNeverFollowsRedirects(): void
    {
        $options = self::options('https://api-epay.n1co.com/v1/events');
        self::assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
        self::assertFalse($options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue($options[CURLOPT_NOSIGNAL]);
        self::assertTrue($options[CURLOPT_RETURNTRANSFER]);
        self::assertTrue($options[CURLOPT_HTTPGET]);
        self::assertSame(30_000, $options[CURLOPT_TIMEOUT_MS]);
        self::assertSame(10_000, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        $headers = $options[CURLOPT_HTTPHEADER];
        self::assertIsArray($headers);
        self::assertContains('Expect:', $headers);
        self::assertContains('Authorization: Bearer x', $headers);
    }

    public function testAllowsHttpOnlyForLocalHosts(): void
    {
        self::assertSame(['https'], self::protocols(self::options('https://api-epay.n1co.com/v1/events')));
        self::assertSame(['https'], self::protocols(self::options('https://localhost.evil.example/v1/events')));
        foreach (['http://127.0.0.1:8080/v1/events', 'http://localhost/v1/events', 'http://[::1]:8080/v1/events'] as $url) {
            self::assertSame(['http', 'https'], self::protocols(self::options($url)), $url);
        }
    }

    public function testShortTimeoutsAndPosts(): void
    {
        $options = self::options('https://api-epay.n1co.com/v1/refunds', 'POST', 0.25);
        self::assertSame(250, $options[CURLOPT_TIMEOUT_MS]);
        self::assertSame(250, $options[CURLOPT_CONNECTTIMEOUT_MS]);
        self::assertSame('POST', $options[CURLOPT_CUSTOMREQUEST]);
        self::assertSame('{}', $options[CURLOPT_POSTFIELDS]);
    }

    /**
     * @param array<int, mixed> $options
     *
     * @return list<string>
     */
    private static function protocols(array $options): array
    {
        $protocolsStr = \defined('CURLOPT_PROTOCOLS_STR') ? \constant('CURLOPT_PROTOCOLS_STR') : null;
        if (\is_int($protocolsStr)) {
            $value = $options[$protocolsStr] ?? null;
            self::assertIsString($value);
            $protocols = explode(',', $value);
        } else {
            $value = $options[CURLOPT_PROTOCOLS] ?? null;
            self::assertIsInt($value);
            $protocols = array_keys(array_filter(['http' => $value & CURLPROTO_HTTP, 'https' => $value & CURLPROTO_HTTPS], static fn(int $bit): bool => $bit !== 0));
        }
        sort($protocols);

        return $protocols;
    }
}
