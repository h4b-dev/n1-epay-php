<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Model\CardSession;
use N1co\EPay\Model\CardSessionCreated;
use N1co\EPay\Model\Event;
use N1co\EPay\Model\EventList;
use N1co\EPay\Model\PaymentIntent;
use N1co\EPay\Model\Refund;
use N1co\EPay\N1co;
use N1co\EPay\Tests\Support\Examples;
use N1co\EPay\Tests\Support\FakeTransport;
use N1co\EPay\Tests\Support\TestClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServicesTest extends TestCase
{
    private const CUSTOMER = ['id' => 'cust-1001', 'name' => 'Ana López', 'email' => 'ana.lopez@example.com', 'phone' => '+503 7000-1234'];

    /**
     * @return iterable<string, array{\Closure(N1co): object, string, string, array<string, mixed>|null, array<string, mixed>, class-string}>
     */
    public static function calls(): iterable
    {
        $customer = self::CUSTOMER;
        yield 'cardSessions.create' => [
            static fn(N1co $n): object => $n->cardSessions->create(['customer' => $customer, 'card' => 'card_Q2FyZEV4YW1wbGUwMDAwMDAx']),
            'POST', '/v1/card_sessions', ['customer' => $customer, 'card' => 'card_Q2FyZEV4YW1wbGUwMDAwMDAx'],
            Examples::response('POST', '/v1/card_sessions'), CardSessionCreated::class,
        ];
        yield 'cardSessions.retrieve' => [
            static fn(N1co $n): object => $n->cardSessions->retrieve('cs_U2Vzc2lvbkV4YW1wbGUwMDAx'),
            'GET', '/v1/card_sessions/cs_U2Vzc2lvbkV4YW1wbGUwMDAx', null, Examples::session(), CardSession::class,
        ];
        yield 'paymentIntents.create' => [
            static fn(N1co $n): object => $n->paymentIntents->create(['amount' => 1250, 'currency' => 'USD', 'customer' => $customer, 'metadata' => ['cart' => 'c-1029'], 'payment_method' => 'card_Q2FyZEV4YW1wbGUwMDAwMDAx', 'cvc_required' => true]),
            'POST', '/v1/payment_intents', ['amount' => 1250, 'currency' => 'USD', 'customer' => $customer, 'metadata' => ['cart' => 'c-1029'], 'payment_method' => 'card_Q2FyZEV4YW1wbGUwMDAwMDAx', 'cvc_required' => true],
            Examples::response('POST', '/v1/payment_intents'), PaymentIntent::class,
        ];
        yield 'paymentIntents.retrieve' => [
            static fn(N1co $n): object => $n->paymentIntents->retrieve('pi_UGF5bWVudEludGVudEV4YW1w'),
            'GET', '/v1/payment_intents/pi_UGF5bWVudEludGVudEV4YW1w', null, Examples::intent(), PaymentIntent::class,
        ];
        yield 'paymentIntents.cancel' => [
            static fn(N1co $n): object => $n->paymentIntents->cancel('pi_UGF5bWVudEludGVudEV4YW1w'),
            'POST', '/v1/payment_intents/pi_UGF5bWVudEludGVudEV4YW1w/cancel', null, Examples::intent(), PaymentIntent::class,
        ];
        yield 'refunds.create' => [
            static fn(N1co $n): object => $n->refunds->create(['payment_intent' => 'pi_UGF5bWVudEludGVudEV4YW1w', 'reason' => 'Duplicate order']),
            'POST', '/v1/refunds', ['payment_intent' => 'pi_UGF5bWVudEludGVudEV4YW1w', 'reason' => 'Duplicate order'], Examples::refund(), Refund::class,
        ];
        yield 'refunds.retrieve' => [
            static fn(N1co $n): object => $n->refunds->retrieve('re_UmVmdW5kRXhhbXBsZTAwMDAx'),
            'GET', '/v1/refunds/re_UmVmdW5kRXhhbXBsZTAwMDAx', null, Examples::refund(), Refund::class,
        ];
        yield 'events.list' => [
            static fn(N1co $n): object => $n->events->list(['type' => 'card.updated', 'limit' => 5]),
            'GET', '/v1/events?type=card.updated&limit=5', null, ['data' => [Examples::event()]], EventList::class,
        ];
        yield 'events.list without params' => [
            static fn(N1co $n): object => $n->events->list(),
            'GET', '/v1/events', null, ['data' => []], EventList::class,
        ];
        yield 'events.retrieve' => [
            static fn(N1co $n): object => $n->events->retrieve('evt_RXZlbnRFeGFtcGxlMDAwMDAx'),
            'GET', '/v1/events/evt_RXZlbnRFeGFtcGxlMDAwMDAx', null, Examples::event(), Event::class,
        ];
        yield 'events.resend' => [
            static fn(N1co $n): object => $n->events->resend('evt_RXZlbnRFeGFtcGxlMDAwMDAx'),
            'POST', '/v1/events/evt_RXZlbnRFeGFtcGxlMDAwMDAx/resend', null, Examples::event(), Event::class,
        ];
        yield 'webhooks.test' => [
            static fn(N1co $n): object => $n->webhooks->test(),
            'POST', '/v1/webhooks/test', null,
            ['type' => 'webhook.test', 'data' => ['object' => ['message' => 'test']]] + Examples::event(), Event::class,
        ];
    }

    /**
     * @param \Closure(N1co): object    $call
     * @param array<string, mixed>|null $body
     * @param array<string, mixed>      $answer
     * @param class-string              $class
     */
    #[DataProvider('calls')]
    public function testBuildsTheRequestAndReadsTheAnswer(\Closure $call, string $method, string $path, ?array $body, array $answer, string $class): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, $answer));
        $result = $call(TestClient::make($transport));

        $request = $transport->last();
        self::assertSame($method, $request->method);
        self::assertSame('https://api-epay.n1co.com' . $path, $request->url);
        self::assertSame($body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $request->body);
        self::assertInstanceOf($class, $result);
        self::assertTrue(method_exists($result, 'toArray'));
        self::assertEquals($answer, $result->toArray());
    }

    public function testRefundCreateWaitsAtLeast90Seconds(): void
    {
        $transport = new FakeTransport(FakeTransport::json(201, Examples::refund()), FakeTransport::json(201, Examples::refund()), FakeTransport::json(201, Examples::refund()));
        TestClient::make($transport)->refunds->create(['payment_intent' => 'pi_x']);
        self::assertSame(90.0, $transport->last()->timeout);
        TestClient::make($transport, ['timeout' => 120])->refunds->create(['payment_intent' => 'pi_x']);
        self::assertSame(120.0, $transport->last()->timeout);
        TestClient::make($transport)->refunds->create(['payment_intent' => 'pi_x'], ['timeout' => 5]);
        self::assertSame(5.0, $transport->last()->timeout);
    }

    public function testChecksIdsBeforeAnyRequest(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, Examples::refund()));
        TestClient::make($transport)->refunds->retrieve('re_Ab-9_x');
        self::assertStringEndsWith('/v1/refunds/re_Ab-9_x', $transport->last()->url);
        $sent = \count($transport->requests);

        foreach (['', ' ', 're_', '_x', 'RE_x', 're', '.', '..', 're_a/../b', 're_a?b', 're_a.b', "re_a\n"] as $bad) {
            try {
                TestClient::make($transport)->refunds->retrieve($bad);
                self::fail('Expected an InvalidArgumentException for ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
            }
        }
        self::assertCount($sent, $transport->requests);
    }

    public function testCreateReturnsTheClientSecretOnce(): void
    {
        $transport = new FakeTransport(FakeTransport::json(201, Examples::response('POST', '/v1/payment_intents')), FakeTransport::json(200, Examples::intent()));
        $n1co = TestClient::make($transport);
        $created = $n1co->paymentIntents->create(['amount' => 1250, 'currency' => 'USD', 'customer' => self::CUSTOMER]);
        self::assertNotNull($created->client_secret);
        self::assertSame(201, $created->lastResponse?->status);
        $read = $n1co->paymentIntents->retrieve($created->id);
        self::assertNull($read->client_secret);
        self::assertArrayNotHasKey('client_secret', $read->toArray());
    }
}
