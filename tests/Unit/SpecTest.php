<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Exception\N1coException;
use N1co\EPay\Http\Response;
use N1co\EPay\Internal\ErrorFactory;
use N1co\EPay\Model;
use N1co\EPay\N1co;
use N1co\EPay\Tests\Support\Examples;
use N1co\EPay\Tests\Support\Spec;
use PHPUnit\Framework\TestCase;

/** Keeps the models in step with spec/openapi.yaml. */
final class SpecTest extends TestCase
{
    /** Schemas of answers and events, and the model that reads each. */
    private const MODELS = [
        'CardSession' => Model\CardSession::class,
        'CardSessionCreated' => Model\CardSessionCreated::class,
        'PaymentIntent' => Model\PaymentIntent::class,
        'PaymentIntentRetrieved' => Model\PaymentIntent::class, // PaymentIntent with a nullable client_secret
        'PaymentIntentCreated' => Model\PaymentIntent::class, // PaymentIntent with client_secret
        'PaymentIntentEventObject' => Model\PaymentIntentEventObject::class,
        'PaymentMethodSummary' => Model\PaymentMethodSummary::class,
        'Billing' => Model\Billing::class,
        'NextAction' => Model\NextAction::class,
        'PaymentIntentError' => Model\PaymentIntentError::class,
        'Refund' => Model\Refund::class,
        'RefundError' => Model\RefundError::class,
        'Event' => Model\Event::class,
        'EventDelivery' => Model\EventDelivery::class,
        'CardEventObject' => Model\Card::class,
        'WebhookEnvelope' => Model\Event::class,
        'Error' => N1coException::class,
    ];

    /** Enum schemas and the string-constant class that lists their values. */
    private const ENUMS = [
        'CardSessionMode' => Model\CardSessionMode::class,
        'CardBrand' => Model\CardBrand::class,
        'PaymentIntentStatus' => Model\PaymentIntentStatus::class,
    ];

    /** Schemas that are request input (plain arrays in the SDK) or scalars. */
    private const INPUTS = [
        'CardSessionCreateRequest', 'PaymentIntentCreateRequest', 'RefundCreateRequest', 'CustomerInput',
        'CustomerName', 'CustomerEmail', 'CustomerPhone', 'Metadata', 'PageOrigins', 'Currency',
    ];

    /** String-constant classes and the enum each mirrors. */
    private const CONSTANTS = [
        Model\CardSessionMode::class => '#/components/schemas/CardSessionMode/enum',
        Model\CardSessionStatus::class => '#/components/schemas/CardSession/properties/status/enum',
        Model\CardBrand::class => '#/components/schemas/CardBrand/enum',
        Model\CardStatus::class => '#/components/schemas/CardEventObject/properties/status/enum',
        Model\PaymentIntentStatus::class => '#/components/schemas/PaymentIntentStatus/enum',
        Model\CancellationReason::class => '#/components/schemas/PaymentIntent/properties/cancellation_reason/enum',
        Model\PaymentIntentErrorCode::class => '#/components/schemas/PaymentIntentError/properties/code/enum',
        Model\RefundStatus::class => '#/components/schemas/Refund/properties/status/enum',
        Model\RefundErrorCode::class => '#/components/schemas/RefundError/properties/code/enum',
        Model\EventType::class => '#/components/schemas/Event/properties/type/enum',
        Model\DeliveryStatus::class => '#/components/schemas/EventDelivery/properties/status/enum',
    ];

    public function testContractVersion(): void
    {
        self::assertSame(N1co::CONTRACT_VERSION, Spec::map(Spec::load()['info'] ?? null)['version'] ?? null);
    }

    public function testEverySchemaThePartnerOperationsUseHasAModel(): void
    {
        $used = [];
        foreach (Spec::partnerOperations() as $op) {
            $used = Spec::referencedSchemas(Spec::map(Spec::map(Spec::load()['paths'] ?? null)[$op['path']] ?? null), $used);
        }
        $used = Spec::referencedSchemas(Spec::load()['x-webhooks'] ?? null, $used);
        $unclassified = array_diff(array_keys($used), array_keys(self::MODELS), array_keys(self::ENUMS), self::INPUTS);
        self::assertSame([], array_values($unclassified), 'Add a model (or list the input schema) for these schemas.');
    }

    public function testEveryModelKnowsEveryFieldOfItsSchema(): void
    {
        foreach (self::MODELS as $schema => $class) {
            if ($class === N1coException::class || $schema === 'WebhookEnvelope') {
                continue;
            }
            $properties = self::properties(Spec::deref(Spec::at('#/components/schemas/' . $schema)));
            $fields = array_values(array_diff(self::fields($class), ['extra', 'lastResponse']));
            if ($schema === 'PaymentIntent') {
                $fields = array_values(array_diff($fields, ['client_secret']));
            }
            sort($properties);
            sort($fields);
            self::assertSame($properties, $fields, $schema . ' and ' . $class);
        }
    }

    /**
     * A valid sample of each model's schema, to find the keys a model cannot do without.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function samples(): array
    {
        $intent = Examples::response('POST', '/v1/payment_intents');
        $card = Spec::map(Spec::map(Examples::webhooks()['card.created']['data'] ?? null)['object'] ?? null);
        $event = Examples::event();

        return [
            'CardSession' => Examples::session(),
            'CardSessionCreated' => Examples::response('POST', '/v1/card_sessions'),
            'PaymentIntent' => Examples::intent(),
            'PaymentIntentEventObject' => Spec::map(Spec::map(Examples::webhooks()['payment_intent.succeeded']['data'] ?? null)['object'] ?? null),
            'PaymentMethodSummary' => Spec::map($intent['payment_method'] ?? null),
            'Billing' => ['country' => 'US', 'state' => 'CA', 'postal_code' => '94105'],
            'NextAction' => ['type' => 'three_ds', 'three_ds' => ['url' => 'https://acs.example/3ds', 'authentication_id' => 'a1', 'expires_at' => '2026-10-17T15:14:05Z']],
            'PaymentIntentError' => ['code' => 'card_declined', 'decline_code' => 'INSUFFICIENT_FUNDS', 'message' => 'The card was declined.'],
            'Refund' => Examples::refund(),
            'RefundError' => ['code' => 'refund_rejected', 'message' => 'Rejected.'],
            'Event' => $event,
            'EventDelivery' => Spec::map($event['delivery'] ?? null),
            'CardEventObject' => $card,
        ];
    }

    public function testEveryKeyAModelRequiresIsRequiredByTheContract(): void
    {
        foreach (self::samples() as $schema => $sample) {
            $class = self::MODELS[$schema];
            $from = [$class, 'fromArray'];
            self::assertTrue(\is_callable($from));
            $from($sample); // the sample itself is valid
            $required = Spec::deref(Spec::at('#/components/schemas/' . $schema))['required'] ?? [];
            self::assertIsArray($required);
            foreach (array_keys($sample) as $key) {
                $without = $sample;
                unset($without[$key]);
                try {
                    $from($without);
                    $needed = false;
                } catch (\UnexpectedValueException) {
                    $needed = true;
                }
                if ($needed) {
                    self::assertContains($key, $required, "{$class} requires {$key}, which {$schema} does not");
                }
            }
        }
    }

    public function testEveryResponseExampleRoundTrips(): void
    {
        $count = 0;
        foreach (Spec::partnerOperations() as $op) {
            foreach (Spec::map(Spec::operation($op['method'], $op['path'])['responses'] ?? null) as $status => $response) {
                $response = Spec::deref($response);
                $content = Spec::map(Spec::map($response['content'] ?? null)['application/json'] ?? null);
                $ref = Spec::string(Spec::map($content['schema'] ?? null)['$ref'] ?? null);
                foreach (Spec::examples($content) as $example) {
                    $example = Spec::map($example);
                    $schema = $ref === null ? null : substr($ref, \strlen('#/components/schemas/'));
                    self::assertNotNull($schema, "{$op['id']} {$status}: example without a schema reference");
                    if ($schema === 'Error') {
                        $this->assertErrorExample($example, (int) $status);
                    } else {
                        $this->assertRoundTrip(self::MODELS[$schema], $example, "{$op['id']} {$status}");
                    }
                    ++$count;
                }
            }
        }
        self::assertGreaterThanOrEqual(5, $count);
    }

    public function testEveryWebhookAndComponentExampleRoundTrips(): void
    {
        foreach (Examples::webhooks() as $type => $example) {
            $this->assertRoundTrip(Model\Event::class, $example, "x-webhooks {$type}");
        }
        foreach (Spec::map(Spec::at('#/components/responses')) as $name => $response) {
            $content = Spec::map(Spec::map(Spec::map($response)['content'] ?? null)['application/json'] ?? null);
            foreach (Spec::examples($content) as $example) {
                $this->assertErrorExample(Spec::map($example), 400);
            }
            self::assertIsString($name);
        }
    }

    public function testConstantsMatchTheContractEnums(): void
    {
        foreach (self::CONSTANTS as $class => $pointer) {
            $enum = array_values(array_filter((array) Spec::at($pointer), static fn(mixed $v): bool => $v !== null));
            $reflection = new \ReflectionClass($class);
            $all = $reflection->getConstant('ALL');
            self::assertSame($enum, $all, $class);
            $constants = $reflection->getConstants();
            unset($constants['ALL']);
            self::assertSame($enum, array_values($constants), $class);
        }
    }

    /**
     * @param class-string         $class
     * @param array<string, mixed> $example
     */
    private function assertRoundTrip(string $class, array $example, string $where): void
    {
        $from = [$class, 'fromArray'];
        self::assertTrue(\is_callable($from), $class);
        $model = $from($example);
        self::assertIsObject($model);
        self::assertTrue(method_exists($model, 'toArray'));
        self::assertEquals($example, $model->toArray(), $where);
        self::assertTrue(property_exists($model, 'extra'));
        self::assertSame([], $model->extra, "{$where}: the model does not know every field");
    }

    /**
     * @param array<string, mixed> $example
     */
    private function assertErrorExample(array $example, int $status): void
    {
        $error = ErrorFactory::fromResponse(new Response($status, [], (string) json_encode($example)), null);
        $body = Spec::map($example['error'] ?? null);
        self::assertSame($body['code'] ?? null, $error->code());
        self::assertSame($body['message'] ?? null, $error->getMessage());
        self::assertSame(Spec::map($body['details'] ?? null), $error->details());
    }

    /**
     * @param array<array-key, mixed> $schema
     *
     * @return list<string>
     */
    private static function properties(array $schema): array
    {
        $names = array_keys(Spec::map($schema['properties'] ?? null));
        foreach (\is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [] as $part) {
            $names = [...$names, ...self::properties(Spec::deref($part))];
        }

        return array_values(array_unique(array_map('strval', $names)));
    }

    /**
     * @param class-string $class
     *
     * @return list<string>
     */
    private static function fields(string $class): array
    {
        return array_map(static fn(\ReflectionProperty $p): string => $p->getName(), (new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_PUBLIC));
    }
}
