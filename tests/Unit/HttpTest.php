<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Exception\AuthenticationException;
use N1co\EPay\Exception\ConflictException;
use N1co\EPay\Exception\ConnectionException;
use N1co\EPay\Exception\InvalidRequestException;
use N1co\EPay\Exception\N1coException;
use N1co\EPay\Exception\NotFoundException;
use N1co\EPay\Exception\RateLimitException;
use N1co\EPay\Exception\ServiceException;
use N1co\EPay\Http\Response;
use N1co\EPay\Internal\ErrorFactory;
use N1co\EPay\Tests\Support\Examples;
use N1co\EPay\Tests\Support\FakeTransport;
use N1co\EPay\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HttpTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function testSendsTheDocumentedHeaders(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['data' => []]));
        TestClient::make($transport)->events->list();
        $headers = $transport->last()->headers;
        self::assertSame('Bearer ' . TestClient::KEY, $headers['Authorization']);
        self::assertSame('application/json', $headers['Accept']);
        self::assertSame('application/json', $headers['Content-Type']);
        self::assertMatchesRegularExpression(self::UUID, $headers['X-Request-Id']);
    }

    public function testResultsCarryTheResponse(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['data' => []], ['X-Request-Id' => 'echoed-id']));
        $list = TestClient::make($transport)->events->list();
        self::assertNotNull($list->lastResponse);
        self::assertSame('echoed-id', $list->lastResponse->requestId);
        self::assertSame(200, $list->lastResponse->status);
        self::assertSame('application/json', $list->lastResponse->headers['content-type']);
    }

    public function testFallsBackToTheSentRequestId(): void
    {
        $transport = new FakeTransport(new Response(200, [], '{"data":[]}'));
        $list = TestClient::make($transport)->events->list();
        self::assertSame($transport->last()->headers['X-Request-Id'], $list->lastResponse?->requestId);
    }

    public function testTimeouts(): void
    {
        $transport = new FakeTransport(
            FakeTransport::json(200, ['data' => []]),
            FakeTransport::json(200, ['data' => []]),
        );
        $n1co = TestClient::make($transport);
        $n1co->events->list();
        self::assertSame(30.0, $transport->last()->timeout);
        $n1co->events->list([], ['timeout' => 2.5]);
        self::assertSame(2.5, $transport->last()->timeout);

        $this->expectException(\InvalidArgumentException::class);
        $n1co->events->list([], ['timeout' => 0]);
    }

    public function testRejectsUnknownRequestOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $options = ['timeoutMs' => 5];
        TestClient::make(new FakeTransport())->events->list([], $options);
    }

    /**
     * @return iterable<string, array{int, class-string<N1coException>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, InvalidRequestException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, AuthenticationException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '413' => [413, InvalidRequestException::class];
        yield '415' => [415, InvalidRequestException::class];
        yield '422' => [422, InvalidRequestException::class];
        yield '429' => [429, RateLimitException::class];
        yield '500' => [500, ServiceException::class];
        yield '503' => [503, ServiceException::class];
        yield '302' => [302, ServiceException::class];
    }

    /**
     * @param class-string<N1coException> $class
     */
    #[DataProvider('statuses')]
    public function testMapsEachStatusToItsException(int $status, string $class): void
    {
        $transport = new FakeTransport(FakeTransport::error($status, 'some_code', ['field' => 'currency', 'code' => 'invalid_currency'], ['X-Request-Id' => 'req-1']));
        try {
            TestClient::make($transport)->paymentIntents->retrieve('pi_x');
            self::fail('Expected an exception.');
        } catch (N1coException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame('some_code', $e->code());
            self::assertSame($status, $e->status());
            self::assertSame($status, $e->getCode());
            self::assertSame('fake: some_code', $e->getMessage());
            self::assertSame(['field' => 'currency', 'code' => 'invalid_currency'], $e->details());
            self::assertSame('req-1', $e->requestId());
            self::assertSame('req-1', $e->headers()['x-request-id']);
        }
    }

    public function testABodyWithoutAnErrorObjectGetsAnHttpCode(): void
    {
        $transport = new FakeTransport(new Response(502, [], '<html>Bad gateway</html>'));
        try {
            TestClient::make($transport)->paymentIntents->retrieve('pi_x');
            self::fail('Expected an exception.');
        } catch (ServiceException $e) {
            self::assertSame('http_502', $e->code());
            self::assertSame([], $e->details());
            self::assertSame('The n1co ePay API answered HTTP 502.', $e->getMessage());
        }
    }

    public function testRedirectsAreErrorsAndNeverFollowed(): void
    {
        $transport = new FakeTransport(new Response(301, ['Location' => 'https://elsewhere.example/'], ''));
        try {
            TestClient::make($transport)->events->list();
            self::fail('Expected an exception.');
        } catch (ServiceException $e) {
            self::assertSame('http_301', $e->code());
            self::assertCount(1, $transport->requests);
        }
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function unreadableSuccesses(): iterable
    {
        yield 'not json' => [new Response(200, [], 'ok')];
        yield 'a list' => [new Response(200, [], '[1,2]')];
        yield 'missing fields' => [new Response(200, [], '{"id":"pi_x"}')];
        yield 'wrong type' => [FakeTransport::json(200, ['data' => 'nope'])];
    }

    #[DataProvider('unreadableSuccesses')]
    public function testAnUnreadableSuccessIsAServiceException(Response $response): void
    {
        $transport = new FakeTransport($response);
        try {
            TestClient::make($transport)->events->list();
            self::fail('Expected an exception.');
        } catch (ServiceException $e) {
            self::assertSame('invalid_response', $e->code());
            self::assertSame(200, $e->status());
        }
    }

    public function testResponseHeaderNamesAreLowercaseTokens(): void
    {
        $response = new Response(200, [123 => 'numeric', 'Bad Name' => 'x', 'X-Ok' => '1', 'X-List' => ['a'], "X-Bad\r\n" => 'y'], '');
        self::assertSame(['123' => 'numeric', 'x-ok' => '1'], $response->headers);
        self::assertSame('1', $response->header('X-OK'));
    }

    public function testEmptyObjectsAreSentAsJsonObjects(): void
    {
        $transport = new FakeTransport(FakeTransport::json(201, Examples::refund()), FakeTransport::json(201, Examples::refund()));
        $n1co = TestClient::make($transport);
        $n1co->refunds->create(['payment_intent' => 'pi_x', 'metadata' => []]);
        self::assertSame('{"payment_intent":"pi_x","metadata":{}}', $transport->last()->body);
        $empty = [];
        try {
            $n1co->refunds->create($empty); // @phpstan-ignore argument.type
        } catch (\Throwable) {
        }
        self::assertSame('{}', $transport->last()->body);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badListParams(): iterable
    {
        yield 'type not a string' => [['type' => 5]];
        yield 'limit not an int' => [['limit' => '5']];
        yield 'limit a float' => [['limit' => 5.0]];
    }

    /**
     * @param array<string, mixed> $params
     */
    #[DataProvider('badListParams')]
    public function testEventsListChecksItsParams(array $params): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TestClient::make(new FakeTransport())->events->list($params); // @phpstan-ignore argument.type
    }

    public function testRetryAfterInSecondsOrAsAnHttpDate(): void
    {
        $now = 1_800_000_000;
        self::assertSame(7, ErrorFactory::retryAfter('7', $now));
        self::assertSame(10, ErrorFactory::retryAfter(gmdate('D, d M Y H:i:s \G\M\T', $now + 10), $now));
        self::assertSame(0, ErrorFactory::retryAfter(gmdate('D, d M Y H:i:s \G\M\T', $now - 10), $now));
        self::assertNull(ErrorFactory::retryAfter('soon', $now));
        self::assertNull(ErrorFactory::retryAfter('-3', $now));
        self::assertNull(ErrorFactory::retryAfter('Tue, 31 Feb 2026 10:00:00 GMT', $now));
        self::assertNull(ErrorFactory::retryAfter('Mon, 19 Oct 2026 25:00:00 GMT', $now));
        self::assertNull(ErrorFactory::retryAfter('Mon, 19 Oct 2026 10:00:00 UTC', $now));
        self::assertNull(ErrorFactory::retryAfter(null, $now));

        $error = ErrorFactory::fromResponse(FakeTransport::error(429, 'rate_limited', [], ['Retry-After' => '12']), 'r');
        self::assertInstanceOf(RateLimitException::class, $error);
        self::assertSame(12, $error->retryAfter());
    }

    public function testMessagesNeverCarryTheSecretKey(): void
    {
        $transport = new FakeTransport(
            FakeTransport::error(401, 'invalid_api_key'),
            new Response(500, [], 'boom'),
            FakeTransport::connectionError(),
        );
        $n1co = TestClient::make($transport);
        foreach ([1, 2, 3] as $ignored) {
            try {
                $n1co->events->resend('evt_x');
            } catch (N1coException $e) {
                self::assertStringNotContainsString(TestClient::KEY, $e->getMessage());
                self::assertStringNotContainsString(TestClient::KEY, (string) json_encode($e->headers()));
            }
        }
        self::assertCount(3, $transport->requests);
    }

    public function testAConnectionErrorKeepsItsCode(): void
    {
        $transport = new FakeTransport(FakeTransport::connectionError('timeout'));
        try {
            TestClient::make($transport)->paymentIntents->cancel('pi_x');
            self::fail('Expected an exception.');
        } catch (ConnectionException $e) {
            self::assertSame('timeout', $e->code());
            self::assertNull($e->status());
            self::assertSame(0, $e->getCode());
        }
    }
}
