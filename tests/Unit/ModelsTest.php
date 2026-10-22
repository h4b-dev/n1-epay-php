<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Exception\ServiceException;
use N1co\EPay\Model\Card;
use N1co\EPay\Model\EventList;
use N1co\EPay\Model\PaymentIntent;
use N1co\EPay\Model\PaymentIntentError;
use N1co\EPay\Model\PaymentIntentErrorCode;
use N1co\EPay\Model\PaymentIntentStatus;
use N1co\EPay\Model\Refund;
use N1co\EPay\Tests\Support\Examples;
use N1co\EPay\Tests\Support\FakeTransport;
use N1co\EPay\Tests\Support\Spec;
use N1co\EPay\Tests\Support\TestClient;
use PHPUnit\Framework\TestCase;

final class ModelsTest extends TestCase
{
    public function testKeepsUnknownStatusesAndFields(): void
    {
        $data = ['status' => 'on_hold', 'risk_score' => 12, 'payment_method' => ['network' => 'visa'] + Spec::map(Examples::response('POST', '/v1/payment_intents')['payment_method'] ?? null)] + Examples::intent();
        $intent = PaymentIntent::fromArray($data);
        self::assertSame('on_hold', $intent->status);
        self::assertNotContains($intent->status, PaymentIntentStatus::ALL);
        self::assertSame(['risk_score' => 12], $intent->extra);
        self::assertSame(['network' => 'visa'], $intent->payment_method?->extra);
        self::assertEquals($data, $intent->toArray());
    }

    public function testReadsAnIntentWithACardSavedWithV3(): void
    {
        $example = Spec::examples(Spec::map(Spec::map(Spec::map(Spec::operation('POST', '/v1/payment_intents')['responses'] ?? null)['201'] ?? null)['content'] ?? null)['application/json'] ?? null);
        $data = Spec::map($example[1] ?? null);
        $intent = PaymentIntent::fromArray($data);
        self::assertSame('65f1c0ffee0123456789abce', $intent->card);
        $summary = $intent->payment_method;
        self::assertNotNull($summary);
        self::assertNull($summary->brand);
        self::assertNull($summary->last4);
        self::assertNull($summary->exp_month);
        self::assertNull($summary->exp_year);
        self::assertTrue($summary->saved);
        self::assertEquals($data, $intent->toArray());
    }

    public function testKnowsReferenceAlreadyPaidAndKeepsUnknownCodes(): void
    {
        self::assertContains('reference_already_paid', PaymentIntentErrorCode::ALL);
        $error = PaymentIntentError::fromArray(['code' => 'reference_already_paid', 'decline_code' => null, 'message' => 'Paid.']);
        self::assertSame(PaymentIntentErrorCode::REFERENCE_ALREADY_PAID, $error->code);
        self::assertSame('added_later', PaymentIntentError::fromArray(['code' => 'added_later', 'decline_code' => null, 'message' => 'x'])->code);
    }

    public function testReadsNestedObjects(): void
    {
        $intent = PaymentIntent::fromArray(Examples::response('POST', '/v1/payment_intents'));
        self::assertSame('visa', $intent->payment_method?->brand);
        self::assertSame(['cart' => 'c-1029'], $intent->metadata);
        self::assertNull($intent->next_action);

        $card = Card::fromArray(Spec::map(Spec::map(Examples::webhooks()['card.created']['data'] ?? null)['object'] ?? null));
        self::assertSame('SV', $card->issuer->country);
    }

    public function testThrowsUnexpectedValueOnAWrongShape(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('refund.amount is not an integer.');
        Refund::fromArray(['amount' => '1250'] + Examples::refund());
    }

    public function testAnUnexpectedShapeFromTheApiIsAServiceException(): void
    {
        $transport = new FakeTransport(FakeTransport::json(200, ['status' => null] + Examples::intent()));
        try {
            TestClient::make($transport)->paymentIntents->retrieve('pi_x');
            self::fail('Expected a ServiceException.');
        } catch (ServiceException $e) {
            self::assertSame('invalid_response', $e->code());
            self::assertStringContainsString('response.status', $e->getMessage());
        }
    }

    public function testEventListIsIterableAndCountable(): void
    {
        $list = EventList::fromArray(['data' => [Examples::event(), Examples::event()]]);
        self::assertCount(2, $list);
        foreach ($list as $event) {
            self::assertSame('card.updated', $event->type);
        }
    }
}
