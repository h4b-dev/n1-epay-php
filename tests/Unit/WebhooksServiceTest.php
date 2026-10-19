<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Internal\WebhookRequest;
use N1co\EPay\Model\EventType;
use N1co\EPay\N1co;
use N1co\EPay\Service\Webhooks;
use N1co\EPay\Tests\Support\FakeTransport;
use N1co\EPay\Tests\Support\Spec;
use N1co\EPay\Tests\Support\TestClient;
use N1co\EPay\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** `$n1co->webhooks` with the client's `webhook_secret`. */
final class WebhooksServiceTest extends TestCase
{
    private const SECRET = Spec::WEBHOOK_SECRET;
    private const ID = 'evt_VGVzdEV2ZW50RXhhbXBsZTAx';
    private const BODY = '{"id":"evt_VGVzdEV2ZW50RXhhbXBsZTAx","type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":{"object":{"message":"test"}}}';

    /**
     * @return array<string, string>
     */
    private static function headers(string $body = self::BODY, ?int $timestamp = null): array
    {
        $timestamp ??= time();

        return [
            'webhook-id' => self::ID,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => Webhook::generateSignature($body, self::ID, $timestamp, self::SECRET),
        ];
    }

    /**
     * `$_SERVER`-style entries for the headers.
     *
     * @return array<string, string>
     */
    private static function server(string $body = self::BODY): array
    {
        $server = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'HTTP_USER_AGENT' => 'n1co'];
        foreach (self::headers($body) as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /**
     * @return resource
     */
    private static function stream(string $body)
    {
        $stream = fopen('php://memory', 'w+b');
        self::assertIsResource($stream);
        fwrite($stream, $body);
        rewind($stream);

        return $stream;
    }

    /**
     * @param resource|string              $input
     * @param array<array-key, mixed>|null $server
     */
    private static function service(?string $secret, mixed $input = 'php://input', ?array $server = null): Webhooks
    {
        $http = new HttpClient(TestClient::KEY, 'https://api.example', 30.0, 0, 'test', new FakeTransport());

        return new Webhooks($http, $secret, new WebhookRequest($input, $server));
    }

    private static function oversized(): string
    {
        return '{"id":"evt_x","type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":{"object":{"message":"' . str_repeat('a', Webhook::MAX_PAYLOAD_BYTES) . '"}}}';
    }

    public function testConstructEventUsesTheConfiguredSecret(): void
    {
        $n1co = TestClient::make(new FakeTransport(), ['webhook_secret' => self::SECRET]);
        $event = $n1co->webhooks->constructEvent(self::BODY, self::headers());
        self::assertSame(self::ID, $event->id);
        self::assertSame(EventType::WEBHOOK_TEST, $event->type);

        // Symfony and Laravel give header arrays, names in any case.
        $symfony = [];
        foreach (self::headers() as $name => $value) {
            $symfony[ucwords($name, '-')] = [$value];
        }
        self::assertSame(self::ID, $n1co->webhooks->constructEvent(self::BODY, $symfony)->id);
    }

    public function testConstructEventHonoursTheTolerance(): void
    {
        $n1co = TestClient::make(new FakeTransport(), ['webhook_secret' => self::SECRET]);
        $old = self::headers(timestamp: time() - 600);
        self::assertSame(self::ID, $n1co->webhooks->constructEvent(self::BODY, $old, 900)->id);
        try {
            $n1co->webhooks->constructEvent(self::BODY, $old);
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('timestamp_out_of_tolerance', $e->code());
        }
    }

    public function testConstructEventRejectsAWrongSignature(): void
    {
        $n1co = TestClient::make(new FakeTransport(), ['webhook_secret' => 'whsec_' . base64_encode('another secret')]);
        try {
            $n1co->webhooks->constructEvent(self::BODY, self::headers());
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('invalid_signature', $e->code());
        }
    }

    public function testWithoutASecretBothFormsAreALogicError(): void
    {
        $n1co = TestClient::make(new FakeTransport());
        foreach ([
            static fn() => $n1co->webhooks->constructEvent(self::BODY, self::headers()),
            static fn() => self::service(null, self::stream(self::BODY), self::server())->constructEventFromRequest(),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a LogicException.');
            } catch (\LogicException $e) {
                self::assertStringContainsString('webhook_secret', $e->getMessage());
            }
        }
    }

    public function testConstructEventRejectsAPayloadOverOneMebibyte(): void
    {
        $n1co = TestClient::make(new FakeTransport(), ['webhook_secret' => self::SECRET]);
        $body = self::oversized();
        try {
            $n1co->webhooks->constructEvent($body, self::headers($body));
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('invalid_payload', $e->code());
        }
    }

    public function testFromRequestReadsTheBodyAndServerHeaders(): void
    {
        $event = self::service(self::SECRET, self::stream(self::BODY), self::server())->constructEventFromRequest();
        self::assertSame(self::ID, $event->id);
        self::assertSame(EventType::WEBHOOK_TEST, $event->type);
    }

    public function testFromRequestOpensAStreamUrl(): void
    {
        $url = 'data://application/json;base64,' . base64_encode(self::BODY);
        self::assertSame(self::ID, self::service(self::SECRET, $url, self::server())->constructEventFromRequest()->id);
    }

    public function testFromRequestHonoursTheTolerance(): void
    {
        $timestamp = time() - 600;
        $server = [
            'HTTP_WEBHOOK_ID' => self::ID,
            'HTTP_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            'HTTP_WEBHOOK_SIGNATURE' => Webhook::generateSignature(self::BODY, self::ID, $timestamp, self::SECRET),
        ];
        self::assertSame(self::ID, self::service(self::SECRET, self::stream(self::BODY), $server)->constructEventFromRequest(900)->id);
        try {
            self::service(self::SECRET, self::stream(self::BODY), $server)->constructEventFromRequest();
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('timestamp_out_of_tolerance', $e->code());
        }
    }

    public function testFromRequestFallsBackToTheGlobalServerArray(): void
    {
        if (\function_exists('getallheaders')) {
            self::markTestSkipped('getallheaders() is available in this SAPI.');
        }
        $saved = $_SERVER;
        try {
            $_SERVER = self::server() + $_SERVER;
            self::assertSame(self::ID, self::service(self::SECRET, self::stream(self::BODY))->constructEventFromRequest()->id);
        } finally {
            $_SERVER = $saved;
        }
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string}>
     */
    public static function badRequests(): iterable
    {
        $server = self::server();
        $missing = $server;
        unset($missing['HTTP_WEBHOOK_SIGNATURE']);
        yield 'no signature header' => [$missing, 'missing_header'];
        yield 'no headers at all' => [[], 'missing_header'];
        yield 'wrong signature' => [['HTTP_WEBHOOK_SIGNATURE' => 'v1,AAAA'] + $server, 'invalid_signature'];
    }

    /**
     * @param array<array-key, mixed> $server
     */
    #[DataProvider('badRequests')]
    public function testFromRequestRejects(array $server, string $code): void
    {
        try {
            self::service(self::SECRET, self::stream(self::BODY), $server)->constructEventFromRequest();
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame($code, $e->code());
        }
    }

    public function testFromRequestStopsReadingPastOneMebibyte(): void
    {
        $stream = self::stream(str_repeat('a', 3 * Webhook::MAX_PAYLOAD_BYTES));
        try {
            self::service(self::SECRET, $stream, self::server())->constructEventFromRequest();
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('invalid_payload', $e->code());
        }
        $position = ftell($stream);
        self::assertIsInt($position);
        self::assertGreaterThan(Webhook::MAX_PAYLOAD_BYTES, $position);
        self::assertLessThanOrEqual(Webhook::MAX_PAYLOAD_BYTES + 8192, $position);
    }

    public function testFromRequestRejectsASignedPayloadOverOneMebibyte(): void
    {
        $body = self::oversized();
        try {
            self::service(self::SECRET, self::stream($body), self::server($body))->constructEventFromRequest();
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('invalid_payload', $e->code());
        }
    }

    public function testFromRequestFailsWhenTheBodyCannotBeRead(): void
    {
        $this->expectException(\RuntimeException::class);
        self::service(self::SECRET, '/nonexistent/webhook-body', self::server())->constructEventFromRequest();
    }

    public function testTheClientSecretIsRedactedFromDumps(): void
    {
        $n1co = new N1co(TestClient::KEY, ['webhook_secret' => self::SECRET, 'transport' => new FakeTransport()]);
        $dump = print_r($n1co, true);
        self::assertStringNotContainsString(substr(self::SECRET, 6), $dump);
        self::assertStringContainsString('whsec_[redacted]', $dump);
    }
}
