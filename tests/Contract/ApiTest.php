<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Contract;

use N1co\EPay\Exception\AuthenticationException;
use N1co\EPay\Exception\ConflictException;
use N1co\EPay\Exception\InvalidRequestException;
use N1co\EPay\Exception\NotFoundException;
use N1co\EPay\N1co;
use N1co\EPay\Tests\Support\FakeApiServer;
use N1co\EPay\Tests\Support\Spec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The SDK, over its curl transport, against the fake API built from the contract. */
final class ApiTest extends TestCase
{
    private const CUSTOMER = ['id' => 'cust-1001', 'name' => 'Ana López', 'email' => 'ana.lopez@example.com', 'phone' => '+503 7000-1234'];
    private const CARD = 'card_Q2FyZEV4YW1wbGUwMDAwMDAx';
    private const INTENT = 'pi_UGF5bWVudEludGVudEV4YW1w';
    private const EVENT = 'evt_RXZlbnRFeGFtcGxlMDAwMDAx';

    private FakeApiServer $fake;
    private N1co $n1co;

    protected function setUp(): void
    {
        $this->fake = FakeApiServer::instance();
        $this->fake->reset();
        $this->n1co = new N1co(Spec::SECRET_KEY, ['base_url' => $this->fake->baseUrl, 'max_retries' => 1]);
    }

    /**
     * One SDK call per partner operation of the contract.
     *
     * @return array<string, \Closure(N1co): object>
     */
    private static function calls(): array
    {
        $customer = self::CUSTOMER;

        return [
            'createCardSession' => static fn(N1co $n): object => $n->cardSessions->create(['customer' => $customer, 'page_origins' => ['https://shop.example.com']]),
            'getCardSession' => static fn(N1co $n): object => $n->cardSessions->retrieve('cs_U2Vzc2lvbkV4YW1wbGUwMDAx'),
            'createPaymentIntent' => static fn(N1co $n): object => $n->paymentIntents->create([
                'amount' => 1250,
                'currency' => 'USD',
                'customer' => $customer,
                'description' => 'Order 8812',
                'reference' => 'order-8812',
                'metadata' => ['cart' => 'c-1029'],
                'billing' => ['country' => 'US', 'state' => 'CA', 'postal_code' => '94105'],
                'page_origins' => ['https://shop.example.com'],
                'payment_method' => self::CARD,
                'cvc_required' => true,
                'save_card' => false,
            ]),
            'getPaymentIntent' => static fn(N1co $n): object => $n->paymentIntents->retrieve(self::INTENT),
            'cancelPaymentIntent' => static fn(N1co $n): object => $n->paymentIntents->cancel(self::INTENT),
            'createRefund' => static fn(N1co $n): object => $n->refunds->create(['payment_intent' => self::INTENT, 'reason' => 'Duplicate order', 'metadata' => ['t' => '1']]),
            'getRefund' => static fn(N1co $n): object => $n->refunds->retrieve('re_UmVmdW5kRXhhbXBsZTAwMDAx'),
            'listEvents' => static fn(N1co $n): object => $n->events->list(['type' => 'card.created', 'limit' => 5]),
            'getEvent' => static fn(N1co $n): object => $n->events->retrieve(self::EVENT),
            'resendEvent' => static fn(N1co $n): object => $n->events->resend(self::EVENT),
            'testWebhook' => static fn(N1co $n): object => $n->webhooks->test(),
        ];
    }

    public function testTheSdkCoversEveryPartnerOperationOfTheContract(): void
    {
        $ids = array_column(Spec::partnerOperations(), 'id');
        $covered = array_keys(self::calls());
        sort($ids);
        sort($covered);
        self::assertSame($ids, $covered);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operations(): iterable
    {
        foreach (array_keys(self::calls()) as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('operations')]
    public function testRequestAndAnswerMatchTheContract(string $operationId): void
    {
        $result = self::calls()[$operationId]($this->n1co);
        self::assertSame([$operationId], array_column($this->fake->requests(), 'operationId'));
        self::assertSame([], $this->fake->violations());
        self::assertTrue(property_exists($result, 'lastResponse'));
        $info = $result->lastResponse;
        self::assertInstanceOf(\N1co\EPay\ResponseInfo::class, $info);
        self::assertContains($info->status, [200, 201, 202]);
    }

    public function testCreatesAnIntentWithoutASavedCard(): void
    {
        $intent = $this->n1co->paymentIntents->create(['amount' => 100, 'currency' => 'USD', 'customer' => self::CUSTOMER]);
        self::assertMatchesRegularExpression('/^pi_[A-Za-z0-9_-]{24}_secret_/', (string) $intent->client_secret);
        self::assertSame(100, $intent->amount);
        self::assertNull($intent->payment_method);
    }

    public function testCreatesAnIntentWithACardSavedWithV3(): void
    {
        $v3 = '65f1c0ffee0123456789abcd';
        $intent = $this->n1co->paymentIntents->create(['amount' => 100, 'currency' => 'USD', 'customer' => self::CUSTOMER, 'reference' => 'order-8812', 'payment_method' => $v3]);
        self::assertSame($v3, $intent->card);
        $summary = $intent->payment_method;
        self::assertNotNull($summary);
        self::assertNull($summary->brand);
        self::assertNull($summary->last4);
        self::assertNull($summary->exp_month);
        self::assertNull($summary->exp_year);
        self::assertTrue($summary->saved);
        self::assertSame([], $this->fake->violations());
    }

    public function testRetrieveCarriesTheClientSecret(): void
    {
        $intent = $this->n1co->paymentIntents->retrieve(self::INTENT);
        self::assertMatchesRegularExpression('/^pi_[A-Za-z0-9_-]{24}_secret_/', (string) $intent->client_secret);
        self::assertSame([], $this->fake->violations());
    }

    #[DataProvider('referenceConflicts')]
    public function testAReferenceConflictIsAConflictWithTheIntent(string $code): void
    {
        $this->fake->force(409, ['error' => ['code' => $code, 'message' => 'fake', 'details' => ['payment_intent' => self::INTENT]]]);
        try {
            $this->n1co->paymentIntents->create(['amount' => 100, 'currency' => 'USD', 'customer' => self::CUSTOMER, 'reference' => 'order-8812']);
            self::fail('Expected a ConflictException.');
        } catch (ConflictException $e) {
            self::assertSame(409, $e->status());
            self::assertSame($code, $e->code());
            self::assertSame(self::INTENT, $e->details()['payment_intent'] ?? null);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function referenceConflicts(): array
    {
        return ['already paid' => ['reference_already_paid'], 'in use' => ['reference_in_use']];
    }

    public function testSendsEmptyMetadataAsAJsonObject(): void
    {
        $this->n1co->refunds->create(['payment_intent' => self::INTENT, 'metadata' => []]);
        $request = $this->fake->requests()[0];
        self::assertSame('createRefund', $request['operationId']);
        self::assertSame('{"payment_intent":"' . self::INTENT . '","metadata":{}}', $request['body']);
        self::assertSame([], $this->fake->violations());
    }

    public function testCreatesAnUpdateSession(): void
    {
        $session = $this->n1co->cardSessions->create(['customer' => self::CUSTOMER, 'card' => self::CARD]);
        self::assertSame('update', $session->mode);
        self::assertSame(self::CARD, $session->card);
    }

    public function testRetrievesTheRequestedObject(): void
    {
        $intent = $this->n1co->paymentIntents->retrieve(self::INTENT);
        self::assertSame(self::INTENT, $intent->id);
    }

    public function testSendsTheDocumentedHeadersAndGetsTheRequestIdBack(): void
    {
        $event = $this->n1co->events->retrieve(self::EVENT);
        $sent = $this->fake->requests()[0]['headers'];
        self::assertSame('Bearer ' . Spec::SECRET_KEY, $sent['authorization']);
        self::assertSame('application/json', $sent['content-type']);
        self::assertSame('application/json', $sent['accept']);
        self::assertMatchesRegularExpression('#^n1co-epay-php/\d+\.\d+\.\d+ php/\d+\.\d+$#', $sent['user-agent']);
        self::assertSame($sent['x-request-id'], $event->lastResponse?->requestId);
    }

    public function testABadCurrencyIsAnInvalidRequestNamingTheField(): void
    {
        try {
            $this->n1co->paymentIntents->create(['amount' => 100, 'currency' => 'usd', 'customer' => self::CUSTOMER]);
            self::fail('Expected an InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(400, $e->status());
            self::assertSame('invalid_request', $e->code());
            self::assertSame('currency', $e->details()['field'] ?? null);
        }
    }

    public function testAnUnknownFieldIsAnInvalidRequest(): void
    {
        try {
            $params = ['payment_intent' => self::INTENT, 'amount' => 5];
            $this->n1co->refunds->create($params);
            self::fail('Expected an InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(['field' => 'amount', 'code' => 'unknown_field'], $e->details());
        }
    }

    public function testAMissingCustomerFieldIsAnInvalidRequest(): void
    {
        try {
            /** @phpstan-ignore argument.type */
            $this->n1co->cardSessions->create(['customer' => ['id' => 'c-1', 'name' => 'Ana', 'email' => 'a@example.com']]);
            self::fail('Expected an InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            self::assertSame(['field' => 'customer.phone', 'code' => 'missing_phone'], $e->details());
        }
    }

    public function testAMalformedIdIsANotFoundWithTheResourceCode(): void
    {
        try {
            $this->n1co->refunds->retrieve('re_short');
            self::fail('Expected a NotFoundException.');
        } catch (NotFoundException $e) {
            self::assertSame('refund_not_found', $e->code());
            self::assertCount(1, $this->fake->requests()); // a 404 is not retried
        }
    }

    public function testAnUnknownKeyIsAnAuthenticationError(): void
    {
        $other = new N1co('sk_' . str_repeat('B', 32), ['base_url' => $this->fake->baseUrl]);
        try {
            $other->events->list();
            self::fail('Expected an AuthenticationException.');
        } catch (AuthenticationException $e) {
            self::assertSame('invalid_api_key', $e->code());
            self::assertSame(401, $e->status());
            self::assertSame('Bearer realm="n1co ePay API"', $e->headers()['www-authenticate'] ?? null);
        }
    }
}
