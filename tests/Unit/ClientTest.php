<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\N1co;
use N1co\EPay\Tests\Support\FakeTransport;
use N1co\EPay\Tests\Support\Spec;
use N1co\EPay\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    public function testDefaultsToProduction(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['data' => []]));
        (new N1co(TestClient::KEY, ['transport' => $transport]))->events->list();
        self::assertSame(N1co::DEFAULT_BASE_URL . '/v1/events', $transport->last()->url);
        self::assertSame(30.0, $transport->last()->timeout);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'publishable key' => ['pk_3x4mpl3pUbl1shabl3k3yAAAAAAAAAAA'];
        yield 'too short' => ['sk_short'];
        yield 'too long' => ['sk_3x4mpl3s3cr3tk3yAAAAAAAAAAAAAAAAA'];
        yield 'bad character' => ['sk_3x4mpl3s3cr3tk3yAAAAAAAAAAAAAAA!'];
        yield 'whitespace' => [' sk_3x4mpl3s3cr3tk3yAAAAAAAAAAAAAAAA'];
    }

    #[DataProvider('badKeys')]
    public function testRejectsAMalformedKeyWithoutEchoingIt(string $key): void
    {
        try {
            new N1co($key);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('sk_ followed by 32 characters', $e->getMessage());
            if ($key !== '') {
                self::assertStringNotContainsString(trim($key), $e->getMessage());
            }
        }
    }

    public function testAcceptsAnAbsentNullOrValidWebhookSecret(): void
    {
        self::assertSame(['secret' => null], (new N1co(TestClient::KEY))->webhooks->__debugInfo());
        self::assertSame(['secret' => null], (new N1co(TestClient::KEY, ['webhook_secret' => null]))->webhooks->__debugInfo());
        self::assertSame(['secret' => 'whsec_[redacted]'], (new N1co(TestClient::KEY, ['webhook_secret' => Spec::WEBHOOK_SECRET]))->webhooks->__debugInfo());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badWebhookSecrets(): iterable
    {
        yield 'empty' => [''];
        yield 'prefix only' => ['whsec_'];
        yield 'no prefix' => ['MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'];
        yield 'other prefix' => ['sk_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'];
        yield 'not base64' => ['whsec_MfKQ9r8G***qrTwjUPD8ILPZIo2LaLaSw'];
        yield 'url-safe base64' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLa-_'];
        yield 'whitespace' => ['whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw '];
        yield 'not a string' => [12345];
    }

    #[DataProvider('badWebhookSecrets')]
    public function testRejectsABadWebhookSecretWithoutEchoingIt(mixed $secret): void
    {
        try {
            new N1co(TestClient::KEY, ['webhook_secret' => $secret]); // @phpstan-ignore argument.type
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('webhook_secret', $e->getMessage());
            self::assertStringNotContainsString('MfKQ9r8G', $e->getMessage());
            self::assertStringNotContainsString('12345', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badBaseUrls(): iterable
    {
        yield 'http' => ['http://api-epay.n1co.com'];
        yield 'http lookalike host' => ['http://localhost.evil.example'];
        yield 'http private address' => ['http://10.0.0.1:8080'];
        yield 'http any address' => ['http://0.0.0.0:8080'];
        yield 'not a url' => ['api-epay.n1co.com'];
        yield 'query' => ['https://api-epay.n1co.com?x=1'];
        yield 'fragment' => ['https://api-epay.n1co.com#x'];
        yield 'credentials' => ['https://user:pass@api-epay.n1co.com'];
        yield 'ftp' => ['ftp://api-epay.n1co.com'];
    }

    #[DataProvider('badBaseUrls')]
    public function testRejectsABadBaseUrl(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new N1co(TestClient::KEY, ['base_url' => $url]);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function goodBaseUrls(): iterable
    {
        yield 'default' => ['https://api-epay.n1co.com', 'https://api-epay.n1co.com'];
        yield 'case and slash' => ['HTTPS://API-EPAY.Dev.N1co.dev/', 'https://api-epay.dev.n1co.dev'];
        yield 'sandbox' => [N1co::SANDBOX_BASE_URL, 'https://api-epay-sandbox.n1co.shop'];
        yield 'sandbox case and slash' => ['HTTPS://API-EPAY-SANDBOX.N1co.shop/', 'https://api-epay-sandbox.n1co.shop'];
        yield 'dev constant' => [N1co::DEV_BASE_URL, 'https://api-epay.dev.n1co.dev'];
        yield 'port and path' => ['https://gw.example:8443/epay/', 'https://gw.example:8443/epay'];
        yield 'localhost' => ['http://LOCALHOST:3000', 'http://localhost:3000'];
        yield 'loopback' => ['http://127.0.0.1:8080/', 'http://127.0.0.1:8080'];
        yield 'ipv6 loopback' => ['http://[::1]:8080', 'http://[::1]:8080'];
        yield 'loopback without port' => ['http://127.0.0.1', 'http://127.0.0.1'];
    }

    public function testANullBaseUrlMeansProduction(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['data' => []]));
        TestClient::make($transport, ['base_url' => null])->events->list();
        self::assertSame(N1co::DEFAULT_BASE_URL . '/v1/events', $transport->last()->url);
    }

    #[DataProvider('goodBaseUrls')]
    public function testNormalisesTheBaseUrl(string $url, string $expected): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['data' => []]));
        TestClient::make($transport, ['base_url' => $url])->events->list();
        self::assertSame($expected . '/v1/events', $transport->last()->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeBaseUrls(): iterable
    {
        yield 'space in host' => ['https://api epay.n1co.com'];
        yield 'trailing newline' => ["https://api-epay.n1co.com\n"];
        yield 'leading tab' => ["\thttps://api-epay.n1co.com"];
        yield 'nul byte' => ["https://api-epay.n1co.com\0.evil"];
        yield 'remote ipv6 over http' => ['http://[2001:db8::1]:8080'];
        yield 'empty' => [''];
    }

    #[DataProvider('unsafeBaseUrls')]
    public function testRejectsAnUnsafeBaseUrlWithoutEchoingIt(string $url): void
    {
        try {
            new N1co(TestClient::KEY, ['base_url' => $url]);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringStartsWith('base_url', $e->getMessage());
            if (trim($url) !== '') {
                self::assertStringNotContainsString(trim($url), $e->getMessage());
            }
        }
    }

    public function testAcceptsLocalHttpAndTrimsTheSlash(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['data' => []]));
        TestClient::make($transport, ['base_url' => 'http://127.0.0.1:8080/'])->events->list();
        self::assertSame('http://127.0.0.1:8080/v1/events', $transport->last()->url);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badOptions(): iterable
    {
        yield 'unknown option' => [['baseUrl' => 'https://x.example']];
        yield 'zero timeout' => [['timeout' => 0]];
        yield 'negative timeout' => [['timeout' => -1]];
        yield 'string timeout' => [['timeout' => '30']];
        yield 'infinite timeout' => [['timeout' => INF]];
        yield 'negative retries' => [['max_retries' => -1]];
        yield 'too many retries' => [['max_retries' => 11]];
        yield 'float retries' => [['max_retries' => 1.5]];
        yield 'app_info without name' => [['app_info' => ['version' => '1.0']]];
        yield 'transport' => [['transport' => new \stdClass()]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('badOptions')]
    public function testRejectsBadOptions(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        new N1co(TestClient::KEY, $options);
    }

    public function testUserAgentNamesTheSdkPhpAndTheApp(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['data' => []]), FakeTransport::json(200, ['data' => []]));
        TestClient::make($transport)->events->list();
        self::assertMatchesRegularExpression('#^n1co-epay-php/\d+\.\d+\.\d+ php/\d+\.\d+$#', $transport->last()->headers['User-Agent']);

        TestClient::make($transport, ['app_info' => ['name' => 'shop', 'version' => '2.1', 'url' => 'https://shop.example']])->events->list();
        self::assertStringEndsWith(' shop/2.1 (https://shop.example)', $transport->last()->headers['User-Agent']);
    }
}
