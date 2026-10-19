<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Exception\ConnectionException;
use N1co\EPay\Exception\InvalidRequestException;
use N1co\EPay\Exception\RateLimitException;
use N1co\EPay\Exception\ServiceException;
use N1co\EPay\Http\Response;
use N1co\EPay\Http\Transport;
use N1co\EPay\Internal\HttpClient;
use N1co\EPay\Internal\Reader;
use N1co\EPay\Model\EventList;
use N1co\EPay\N1co;
use N1co\EPay\ResponseInfo;
use N1co\EPay\Tests\Support\FakeTransport;
use N1co\EPay\Tests\Support\TestClient;
use PHPUnit\Framework\TestCase;

final class RetryTest extends TestCase
{
    /** @var list<float> */
    private array $sleeps = [];

    private float $random = 0.5;

    public function testRetriesAGetOn5xxThenSucceeds(): void
    {
        $transport = new FakeTransport(FakeTransport::error(503, 'n1co_api_unavailable'), $this->ok());
        $this->get($transport);
        self::assertCount(2, $transport->requests);
        self::assertCount(1, $this->sleeps);
        self::assertGreaterThanOrEqual(250, $this->sleeps[0]);
        self::assertLessThanOrEqual(500, $this->sleeps[0]);
        self::assertNotSame($transport->requests[0]->headers['X-Request-Id'], $transport->requests[1]->headers['X-Request-Id']);
    }

    public function testRetriesAGetOnConnectionErrorsAnd429(): void
    {
        $transport = new FakeTransport(FakeTransport::connectionError('timeout'), FakeTransport::error(429, 'rate_limited'), $this->ok());
        $this->get($transport, maxRetries: 2);
        self::assertCount(3, $transport->requests);
    }

    public function testHonoursRetryAfterWithBoundedJitter(): void
    {
        foreach ([0.0, 0.999999] as $random) {
            $this->random = $random;
            $this->sleeps = [];
            $transport = new FakeTransport(FakeTransport::error(429, 'rate_limited', [], ['Retry-After' => '2']), $this->ok());
            $this->get($transport);
            self::assertCount(1, $this->sleeps);
            self::assertGreaterThanOrEqual(2000, $this->sleeps[0]);
            self::assertLessThanOrEqual(2250, $this->sleeps[0]);
        }
    }

    public function testHonoursAnHttpDateRetryAfter(): void
    {
        $date = gmdate('D, d M Y H:i:s \G\M\T', time() + 3);
        $transport = new FakeTransport(FakeTransport::error(503, 'n1co_api_unavailable', [], ['Retry-After' => $date]), $this->ok());
        $this->get($transport);
        self::assertGreaterThanOrEqual(1000, $this->sleeps[0]);
        self::assertLessThanOrEqual(3250, $this->sleeps[0]);
    }

    public function testThrowsARetryAfterOver30Seconds(): void
    {
        $transport = new FakeTransport(FakeTransport::error(429, 'rate_limited', [], ['Retry-After' => '31']), $this->ok());
        try {
            $this->get($transport);
            self::fail('Expected an exception.');
        } catch (RateLimitException $e) {
            self::assertSame(31, $e->retryAfter());
            self::assertCount(1, $transport->requests);
            self::assertSame([], $this->sleeps);
        }
    }

    public function testGivesUpAfterMaxRetries(): void
    {
        $transport = new FakeTransport(...array_fill(0, 4, FakeTransport::error(500, 'internal_error')));
        try {
            $this->get($transport, maxRetries: 3);
            self::fail('Expected an exception.');
        } catch (ServiceException $e) {
            self::assertSame('internal_error', $e->code());
            self::assertCount(4, $transport->requests);
            self::assertCount(3, $this->sleeps);
        }
    }

    public function testDoesNotRetryOtherClientErrors(): void
    {
        $transport = new FakeTransport(FakeTransport::error(400, 'invalid_request'), $this->ok());
        $this->expectException(InvalidRequestException::class);
        try {
            $this->get($transport);
        } finally {
            self::assertCount(1, $transport->requests);
        }
    }

    public function testNeverRetriesAPost(): void
    {
        foreach ([FakeTransport::error(503, 'n1co_api_unavailable'), FakeTransport::error(429, 'rate_limited'), FakeTransport::connectionError()] as $failure) {
            $transport = new FakeTransport($failure, $this->ok());
            try {
                $this->client($transport, 5)->request('POST', '/v1/webhooks/test', self::map(...));
                self::fail('Expected an exception.');
            } catch (ServiceException|RateLimitException|ConnectionException) {
                self::assertCount(1, $transport->requests);
            }
        }
        self::assertSame([], $this->sleeps);
    }

    public function testTheClientRetriesGetsByDefaultButNotPosts(): void
    {
        $transport = new FakeTransport(FakeTransport::error(503, 'x', [], ['Retry-After' => '0']), $this->ok());
        $n1co = new N1co(TestClient::KEY, ['transport' => $transport]);
        $n1co->events->list();
        self::assertCount(2, $transport->requests);

        $transport->push(FakeTransport::error(503, 'x', [], ['Retry-After' => '0']), $this->ok());
        $this->expectException(ServiceException::class);
        $n1co->webhooks->test();
    }

    public function testBackoffGrowsWithEqualJitterUpTo4Seconds(): void
    {
        $expected = [[250, 500], [500, 1000], [1000, 2000], [2000, 4000], [2000, 4000], [2000, 4000]];
        foreach ([0.0, 0.999999] as $random) {
            $this->random = $random;
            $client = $this->client(new FakeTransport(), 2);
            foreach ($expected as $retry => [$min, $max]) {
                $delay = $client->retryDelayMs($retry, null);
                self::assertGreaterThanOrEqual($min, $delay);
                self::assertLessThanOrEqual($max, $delay);
            }
        }
        self::assertLessThanOrEqual(4000, $this->client(new FakeTransport(), 2)->retryDelayMs(60, null));
    }

    private function ok(): Response
    {
        return FakeTransport::json(200, ['data' => []]);
    }

    private function get(FakeTransport $transport, int $maxRetries = 1): EventList
    {
        return $this->client($transport, $maxRetries)->request('GET', '/v1/events', EventList::read(...));
    }

    private function client(Transport $transport, int $maxRetries): HttpClient
    {
        return new HttpClient(
            TestClient::KEY,
            'https://api.example',
            30.0,
            $maxRetries,
            'test',
            $transport,
            function (float $ms): void {
                $this->sleeps[] = $ms;
            },
            fn(): float => $this->random,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(Reader $reader, ResponseInfo $info): array
    {
        return $reader->extra([]);
    }
}
