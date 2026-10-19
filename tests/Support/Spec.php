<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Support;

use Symfony\Component\Yaml\Yaml;

/** The vendored API contract (spec/openapi.yaml) and helpers to walk it. */
final class Spec
{
    public const PATH = __DIR__ . '/../../spec/openapi.yaml';
    /** The secret key of the contract's own example (POST /v1/setup). */
    public const SECRET_KEY = 'sk_3x4mpl3s3cr3tk3yAAAAAAAAAAAAAAAA';
    /** The webhook secret of the contract's own example. */
    public const WEBHOOK_SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /**
     * @return array<string, mixed>
     */
    public static function load(): array
    {
        if (self::$spec === null) {
            $parsed = Yaml::parseFile(self::PATH);
            if (!\is_array($parsed)) {
                throw new \RuntimeException('spec/openapi.yaml is not a mapping.');
            }
            /** @var array<string, mixed> $parsed */
            self::$spec = $parsed;
        }

        return self::$spec;
    }

    /**
     * A node by JSON pointer (`#/components/schemas/Refund`).
     *
     * @param array<array-key, mixed>|null $root
     */
    public static function at(string $pointer, ?array $root = null): mixed
    {
        $node = $root ?? self::load();
        foreach (explode('/', ltrim(substr($pointer, 1), '/')) as $raw) {
            if ($raw === '') {
                continue;
            }
            $key = str_replace(['~1', '~0'], ['/', '~'], $raw);
            if (!\is_array($node) || !\array_key_exists($key, $node)) {
                throw new \RuntimeException("No node at {$pointer}.");
            }
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * Follows `$ref` until a node without one.
     *
     * @param array<array-key, mixed>|null $root
     *
     * @return array<array-key, mixed>
     */
    public static function deref(mixed $node, ?array $root = null): array
    {
        while (\is_array($node) && isset($node['$ref']) && \is_string($node['$ref'])) {
            $node = self::at($node['$ref'], $root);
        }
        if (!\is_array($node)) {
            throw new \RuntimeException('Not an object node.');
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    public static function map(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * The partner operations (secret key): every operation without its own `security`.
     *
     * @param array<array-key, mixed>|null $root
     *
     * @return list<array{id: string, method: string, path: string}>
     */
    public static function partnerOperations(?array $root = null): array
    {
        $root ??= self::load();
        $out = [];
        foreach (self::map($root['paths'] ?? null) as $path => $item) {
            $item = self::map($item);
            foreach (self::METHODS as $method) {
                $op = self::map($item[$method] ?? null);
                if ($op === [] || \array_key_exists('security', $op)) {
                    continue;
                }
                $out[] = ['id' => (string) self::string($op['operationId'] ?? null), 'method' => strtoupper($method), 'path' => $path];
            }
        }

        return $out;
    }

    /**
     * The operation object.
     *
     * @param array<array-key, mixed>|null $root
     *
     * @return array<string, mixed>
     */
    public static function operation(string $method, string $path, ?array $root = null): array
    {
        $root ??= self::load();

        return self::map(self::map(self::map($root['paths'] ?? null)[$path] ?? null)[strtolower($method)] ?? null);
    }

    /**
     * Examples of a media type object: `example` or every `examples.*.value`.
     *
     * @return list<mixed>
     */
    public static function examples(mixed $content): array
    {
        $content = self::map($content);
        if (\array_key_exists('example', $content)) {
            return [$content['example']];
        }
        $out = [];
        foreach (self::map($content['examples'] ?? null) as $example) {
            $out[] = self::map($example)['value'] ?? null;
        }

        return $out;
    }

    public static function string(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }

    /**
     * Names of every component schema reachable from a node.
     *
     * @param array<string, true> $seen
     *
     * @return array<string, true>
     */
    public static function referencedSchemas(mixed $node, array $seen = []): array
    {
        if (!\is_array($node)) {
            return $seen;
        }
        foreach ($node as $key => $value) {
            if ($key === '$ref' && \is_string($value)) {
                if (str_starts_with($value, '#/components/schemas/')) {
                    $name = substr($value, \strlen('#/components/schemas/'));
                    if (!isset($seen[$name])) {
                        $seen[$name] = true;
                        $seen = self::referencedSchemas(self::at($value), $seen);
                    }
                } else {
                    $seen = self::referencedSchemas(self::at($value), $seen);
                }
            } else {
                $seen = self::referencedSchemas($value, $seen);
            }
        }

        return $seen;
    }
}
