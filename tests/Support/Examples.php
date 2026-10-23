<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Support;

/** The contract's examples as API answers, for unit tests. */
final class Examples
{
    /**
     * The first example of an operation's success answer.
     *
     * @return array<string, mixed>
     */
    public static function response(string $method, string $path): array
    {
        $responses = Spec::map(Spec::operation($method, $path)['responses'] ?? null);
        foreach ($responses as $status => $response) {
            if (!str_starts_with((string) $status, '2')) {
                continue;
            }
            $content = Spec::map(Spec::map(Spec::deref($response)['content'] ?? null)['application/json'] ?? null);
            $examples = Spec::examples($content);
            if ($examples !== []) {
                return Spec::map($examples[0]);
            }
        }

        throw new \LogicException("No example for {$method} {$path}.");
    }

    /**
     * @return array<string, mixed>
     */
    public static function intent(): array
    {
        $intent = self::response('POST', '/v1/payment_intents');
        unset($intent['intent_token']);

        return $intent;
    }

    /**
     * @return array<string, mixed>
     */
    public static function session(): array
    {
        $session = self::response('POST', '/v1/card_sessions');
        unset($session['session_token']);

        return $session + ['created_at' => '2026-10-16T15:04:05Z'];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(): array
    {
        return self::response('POST', '/v1/events/{id}/resend');
    }

    /**
     * @return array<string, mixed>
     */
    public static function refund(): array
    {
        return self::response('POST', '/v1/refunds');
    }

    /**
     * The webhook bodies of x-webhooks, by event type.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function webhooks(): array
    {
        $out = [];
        foreach (Spec::map(Spec::load()['x-webhooks'] ?? null) as $type => $item) {
            $body = Spec::map(Spec::map(Spec::map($item)['post'] ?? null)['requestBody'] ?? null);
            $examples = Spec::examples(Spec::map(Spec::map($body['content'] ?? null)['application/json'] ?? null));
            if ($examples !== []) {
                $out[$type] = Spec::map($examples[0]);
            }
        }

        return $out;
    }
}
