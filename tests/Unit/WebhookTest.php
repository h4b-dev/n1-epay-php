<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Exception\WebhookSignatureException;
use N1co\EPay\Model\Card;
use N1co\EPay\Model\EventType;
use N1co\EPay\Model\PaymentIntentEventObject;
use N1co\EPay\Model\Refund;
use N1co\EPay\Model\WebhookTestObject;
use N1co\EPay\Tests\Support\Examples;
use N1co\EPay\Tests\Support\Spec;
use N1co\EPay\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WebhookTest extends TestCase
{
    private const SECRET = Spec::WEBHOOK_SECRET;
    private const NOW = 1_800_000_000;
    private const ID = 'evt_VGVzdEV2ZW50RXhhbXBsZTAx';
    private const BODY = '{"id":"evt_VGVzdEV2ZW50RXhhbXBsZTAx","type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":{"object":{"message":"test"}}}';

    /**
     * @return array<string, string>
     */
    private static function headers(string $body = self::BODY, int $timestamp = self::NOW, string $secret = self::SECRET): array
    {
        return [
            'webhook-id' => self::ID,
            'webhook-timestamp' => (string) $timestamp,
            'webhook-signature' => Webhook::generateSignature($body, self::ID, $timestamp, $secret),
        ];
    }

    public function testMatchesTheStandardWebhooksReferenceVector(): void
    {
        self::assertSame(
            'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
            Webhook::generateSignature('{"test": 2432232314}', 'msg_p5jXN8AQM9LWM0D4loKWxJek', 1614265330, 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw'),
        );
    }

    public function testVerifiesAValidDelivery(): void
    {
        $event = Webhook::constructEvent(self::BODY, self::headers(), self::SECRET, now: self::NOW);
        self::assertSame(self::ID, $event->id);
        self::assertSame(EventType::WEBHOOK_TEST, $event->type);
        self::assertInstanceOf(WebhookTestObject::class, $event->data->object);
        self::assertSame('test', $event->data->object->message);
        self::assertNull($event->delivery);
        self::assertNull($event->lastResponse);
    }

    public function testAcceptsHeaderArraysAndAnyCaseAsFrameworksGiveThem(): void
    {
        $symfony = array_map(static fn(string $value): array => [$value], self::headers());
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, $symfony, self::SECRET, now: self::NOW)->id);

        $upper = [];
        foreach (self::headers() as $name => $value) {
            $upper[ucwords($name, '-')] = $value;
        }
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, $upper, self::SECRET, now: self::NOW)->id);
    }

    public function testAcceptsTheSecretWithoutItsPrefix(): void
    {
        $bare = substr(self::SECRET, \strlen('whsec_'));
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, self::headers(), $bare, now: self::NOW)->id);
    }

    public function testRejectsAPayloadOverOneMebibyte(): void
    {
        $body = '{"id":"evt_x","type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":{"object":{"message":"' . str_repeat('a', Webhook::MAX_PAYLOAD_BYTES) . '"}}}';
        try {
            Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW);
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('invalid_payload', $e->code());
        }
        // Checked before the headers: an oversized body is never hashed.
        try {
            Webhook::constructEvent(str_repeat(' ', Webhook::MAX_PAYLOAD_BYTES + 1), [], self::SECRET, now: self::NOW);
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('invalid_payload', $e->code());
        }
    }

    public function testAcceptsAPayloadOfExactlyOneMebibyte(): void
    {
        $head = '{"id":"evt_x","type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":{"object":{"message":"';
        $tail = '"}}}';
        $body = $head . str_repeat('a', Webhook::MAX_PAYLOAD_BYTES - \strlen($head) - \strlen($tail)) . $tail;
        self::assertSame(Webhook::MAX_PAYLOAD_BYTES, \strlen($body));
        self::assertSame('evt_x', Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW)->id);
    }

    public function testAcceptsAnyOfSeveralSignatures(): void
    {
        $headers = self::headers();
        $other = Webhook::generateSignature(self::BODY, self::ID, self::NOW, 'whsec_' . base64_encode('another secret'));
        $headers['webhook-signature'] = 'v2,abc ' . $other . ' v1,' . "\t" . $headers['webhook-signature'] . ' v1,not-base64!';
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, $headers, self::SECRET, now: self::NOW)->id);
    }

    public function testAcceptsTimestampsWithinTheToleranceBothWays(): void
    {
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, self::headers(timestamp: self::NOW - 300), self::SECRET, now: self::NOW)->id);
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, self::headers(timestamp: self::NOW + 300), self::SECRET, now: self::NOW)->id);
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, self::headers(), self::SECRET, 0, self::NOW)->id);
        self::assertSame(self::ID, Webhook::constructEvent(self::BODY, self::headers(timestamp: self::NOW - 600), self::SECRET, 600, self::NOW)->id);
    }

    /**
     * @return iterable<string, array{string, array<array-key, mixed>, string, int, string}>
     */
    public static function rejected(): iterable
    {
        $h = self::headers();
        yield 'wrong secret' => [self::BODY, $h, 'whsec_' . base64_encode('wrong'), 300, 'invalid_signature'];
        yield 'modified body' => [str_replace('test"}', 'tost"}', self::BODY), $h, self::SECRET, 300, 'invalid_signature'];
        yield 'other id' => [self::BODY, ['webhook-id' => 'evt_other'] + $h, self::SECRET, 300, 'invalid_signature'];
        yield 'old timestamp' => [self::BODY, self::headers(timestamp: self::NOW - 301), self::SECRET, 300, 'timestamp_out_of_tolerance'];
        yield 'future timestamp' => [self::BODY, self::headers(timestamp: self::NOW + 301), self::SECRET, 300, 'timestamp_out_of_tolerance'];
        yield 'zero tolerance' => [self::BODY, self::headers(timestamp: self::NOW - 1), self::SECRET, 0, 'timestamp_out_of_tolerance'];
        yield 'timestamp in ms' => [self::BODY, ['webhook-timestamp' => '1800000000000000'] + $h, self::SECRET, 300, 'invalid_timestamp'];
        yield 'timestamp not a number' => [self::BODY, ['webhook-timestamp' => 'yesterday'] + $h, self::SECRET, 300, 'invalid_timestamp'];
        yield 'negative timestamp' => [self::BODY, ['webhook-timestamp' => '-5'] + $h, self::SECRET, 300, 'invalid_timestamp'];
        yield 'two timestamps' => [self::BODY, ['webhook-timestamp' => ['1800000000', '1800000000']] + $h, self::SECRET, 300, 'invalid_timestamp'];
        yield 'no v1 entry' => [self::BODY, ['webhook-signature' => 'v2,' . substr($h['webhook-signature'], 3)] + $h, self::SECRET, 300, 'invalid_signature'];
        yield 'bare signature' => [self::BODY, ['webhook-signature' => substr($h['webhook-signature'], 3)] + $h, self::SECRET, 300, 'invalid_signature'];
        yield 'v1 without value' => [self::BODY, ['webhook-signature' => 'v1'] + $h, self::SECRET, 300, 'invalid_signature'];
        foreach (['webhook-id', 'webhook-timestamp', 'webhook-signature'] as $name) {
            $missing = $h;
            unset($missing[$name]);
            yield "no {$name}" => [self::BODY, $missing, self::SECRET, 300, 'missing_header'];
            yield "empty {$name}" => [self::BODY, [$name => '  '] + $h, self::SECRET, 300, 'missing_header'];
            yield "null {$name}" => [self::BODY, [$name => null] + $h, self::SECRET, 300, 'missing_header'];
            yield "int {$name}" => [self::BODY, [$name => 12] + $h, self::SECRET, 300, 'missing_header'];
            yield "empty array {$name}" => [self::BODY, [$name => []] + $h, self::SECRET, 300, 'missing_header'];
            yield "nested array {$name}" => [self::BODY, [$name => [['x']]] + $h, self::SECRET, 300, 'missing_header'];
        }
        yield 'no headers at all' => [self::BODY, [], self::SECRET, 300, 'missing_header'];
        yield 'list of headers' => [self::BODY, array_values($h), self::SECRET, 300, 'missing_header'];
    }

    /**
     * @param array<array-key, mixed> $headers
     */
    #[DataProvider('rejected')]
    public function testRejects(string $body, array $headers, string $secret, int $tolerance, string $code): void
    {
        try {
            Webhook::constructEvent($body, $headers, $secret, $tolerance, self::NOW);
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame($code, $e->code());
            self::assertNull($e->status());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badPayloads(): iterable
    {
        yield 'not json' => ['not json'];
        yield 'a list' => ['[1,2,3]'];
        yield 'no type' => ['{"id":"evt_x","created_at":"2026-10-16T15:10:00Z","data":{"object":{}}}'];
        yield 'data not an object' => ['{"id":"evt_x","type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":"x"}'];
        yield 'id not a string' => ['{"id":5,"type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":{"object":{}}}'];
        yield 'data a list' => ['{"id":"evt_x","type":"webhook.test","created_at":"2026-10-16T15:10:00Z","data":[1]}'];
        yield 'no data' => ['{"id":"evt_x","type":"webhook.test","created_at":"2026-10-16T15:10:00Z"}'];
        yield 'empty' => [''];
    }

    #[DataProvider('badPayloads')]
    public function testRejectsAPayloadThatIsNotAnEvent(string $body): void
    {
        try {
            Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW);
            self::fail('Expected a WebhookSignatureException.');
        } catch (WebhookSignatureException $e) {
            self::assertSame('invalid_payload', $e->code());
        }
    }

    public function testRejectsANegativeToleranceAndABadSecret(): void
    {
        try {
            Webhook::constructEvent(self::BODY, self::headers(), self::SECRET, -1, self::NOW);
            self::fail('Expected an InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
        }
        foreach (['', 'whsec_', 'whsec_***', 'whsec_YW Jj', "whsec_YWJj\n", 'whsec_YWJj===', 'whsec_=YWJj'] as $secret) {
            try {
                Webhook::constructEvent(self::BODY, self::headers(), $secret, now: self::NOW);
                self::fail('Expected an InvalidArgumentException.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringNotContainsString('***', $e->getMessage());
            }
        }
    }

    public function testTypesDataObjectPerEventType(): void
    {
        $classes = [
            'card.created' => Card::class,
            'card.updated' => Card::class,
            'payment_intent.succeeded' => PaymentIntentEventObject::class,
            'refund.succeeded' => Refund::class,
            'webhook.test' => WebhookTestObject::class,
        ];
        $examples = Examples::webhooks();
        self::assertSame(array_keys($classes), array_values(array_intersect(array_keys($classes), array_keys($examples))));
        foreach ($classes as $type => $class) {
            $body = (string) json_encode($examples[$type], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $event = Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW);
            self::assertSame($type, $event->type);
            self::assertInstanceOf($class, $event->data->object);
            self::assertEquals($examples[$type], $event->toArray());
        }
    }

    public function testTypesOnlyTheExactKnownTypes(): void
    {
        $card = Spec::map(Spec::map(Examples::webhooks()['card.created']['data'] ?? null)['object'] ?? null);
        $body = (string) json_encode(['id' => 'evt_x', 'type' => 'card.deleted', 'created_at' => '2026-10-16T15:10:00Z', 'data' => ['object' => $card]]);
        $event = Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW);
        self::assertSame('card.deleted', $event->type);
        self::assertSame($card, $event->data->object);
        self::assertSame($card, $event->data->raw);
    }

    public function testKeepsAKnownTypeWithADriftedObjectReadable(): void
    {
        $card = ['exp_month' => '12'] + Spec::map(Spec::map(Examples::webhooks()['card.created']['data'] ?? null)['object'] ?? null);
        $body = (string) json_encode(['id' => 'evt_x', 'type' => 'card.created', 'created_at' => '2026-10-16T15:10:00Z', 'data' => ['object' => $card]]);
        $event = Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW);
        self::assertSame(EventType::CARD_CREATED, $event->type);
        self::assertSame($card, $event->data->object);
        self::assertSame($card, $event->data->raw);
    }

    public function testKeepsADriftedEnvelopeReadable(): void
    {
        $body = '{"id":"evt_x","type":"webhook.test","created_at":12,"data":{"object":"x","note":1},"delivery":{"status":5}}';
        $event = Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW);
        self::assertSame('evt_x', $event->id);
        self::assertSame('', $event->created_at);
        self::assertSame([], $event->data->raw);
        self::assertSame(['note' => 1], $event->data->extra);
        self::assertSame(['delivery' => ['status' => 5]], $event->extra);
    }

    public function testKeepsAnUnknownEventTypeAsAnArray(): void
    {
        $body = '{"id":"evt_x","type":"payout.paid","created_at":"2026-10-16T15:10:00Z","data":{"object":{"id":"po_1","amount":5}},"livemode":true}';
        $event = Webhook::constructEvent($body, self::headers($body), self::SECRET, now: self::NOW);
        self::assertSame('payout.paid', $event->type);
        self::assertSame(['id' => 'po_1', 'amount' => 5], $event->data->object);
        self::assertSame(['id' => 'po_1', 'amount' => 5], $event->data->raw);
        self::assertSame(['livemode' => true], $event->extra);
        self::assertSame(json_decode($body, true), $event->toArray());
    }
}
