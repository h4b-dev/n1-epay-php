<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Unit;

use N1co\EPay\Http\Request;
use N1co\EPay\Tests\Support\Examples;
use N1co\EPay\Tests\Support\Spec;
use PHPUnit\Framework\TestCase;

/** Secrets never reach traces, dumps, messages or headers. */
final class SecretsTest extends TestCase
{
    public function testTracesAndDumpsCarryNoSecret(): void
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'zend.exception_ignore_args=0', '-d', 'display_errors=stderr', __DIR__ . '/../Support/secrets-probe.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);

        // The probe ran every scenario, with arguments in the traces.
        foreach (['constructor', 'webhook_secret', 'events->list', 'paymentIntents->create', 'curl', 'webhook 300', 'webhook -1', 'webhooks->constructEvent', 'dumps', 'done'] as $label) {
            self::assertStringContainsString('== ' . $label, $output);
        }
        self::assertStringContainsString('SensitiveParameterValue', $output);
        self::assertStringContainsString('[args]', $output);

        $clientSecret = (string) Spec::string(Examples::response('POST', '/v1/payment_intents')['client_secret'] ?? null);
        self::assertNotSame('', $clientSecret);
        foreach ([Spec::SECRET_KEY, substr(Spec::SECRET_KEY, 3), Spec::WEBHOOK_SECRET, substr(Spec::WEBHOOK_SECRET, 6), $clientSecret] as $secret) {
            self::assertStringNotContainsString($secret, $output);
        }
    }

    public function testRequestDumpsRedactTheAuthorizationHeader(): void
    {
        $request = new Request('GET', 'https://api.example/v1/events', ['Authorization' => 'Bearer ' . Spec::SECRET_KEY, 'Accept' => 'application/json'], null, 30.0);
        self::assertStringNotContainsString(Spec::SECRET_KEY, print_r($request, true));
        self::assertStringContainsString('Bearer [redacted]', print_r($request, true));
        self::assertSame('Bearer ' . Spec::SECRET_KEY, $request->headers['Authorization']);
    }
}
