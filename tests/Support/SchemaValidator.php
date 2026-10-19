<?php

declare(strict_types=1);

namespace N1co\EPay\Tests\Support;

/**
 * A small JSON Schema 2020-12 validator for the keywords the contract uses. Instances are decoded
 * JSON (arrays); an empty array is accepted as an object or an array. Returns the first error.
 */
final class SchemaValidator
{
    /**
     * @param array<array-key, mixed> $root The document `$ref`s resolve against.
     */
    public function __construct(private readonly array $root) {}

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string>            $path
     *
     * @return array{path: list<string>, keyword: string, param: string}|null
     */
    public function validate(mixed $value, array $schema, array $path = []): ?array
    {
        if (isset($schema['$ref']) && \is_string($schema['$ref'])) {
            $error = $this->validate($value, Spec::deref(Spec::at($schema['$ref'], $this->root), $this->root), $path);
            if ($error !== null) {
                return $error;
            }
        }
        foreach (Spec::map($schema) as $keyword => $argument) {
            $error = $this->keyword($keyword, $argument, $value, $schema, $path);
            if ($error !== null) {
                return $error;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $schema
     * @param list<string>            $path
     *
     * @return array{path: list<string>, keyword: string, param: string}|null
     */
    private function keyword(string $keyword, mixed $argument, mixed $value, array $schema, array $path): ?array
    {
        $fail = static fn(string $param = ''): array => ['path' => $path, 'keyword' => $keyword, 'param' => $param];
        $isObject = \is_array($value) && ($value === [] || !array_is_list($value));
        $isArray = \is_array($value) && array_is_list($value);

        switch ($keyword) {
            case 'type':
                $types = \is_array($argument) ? $argument : [$argument];
                foreach ($types as $type) {
                    if (match ($type) {
                        'null' => $value === null,
                        'string' => \is_string($value),
                        'integer' => \is_int($value),
                        'number' => \is_int($value) || \is_float($value),
                        'boolean' => \is_bool($value),
                        'object' => $isObject,
                        'array' => $isArray,
                        default => false,
                    }) {
                        return null;
                    }
                }

                return $fail();

            case 'enum':
                return \is_array($argument) && \in_array($value, $argument, true) ? null : $fail();

            case 'const':
                return $value === $argument ? null : $fail();

            case 'pattern':
                return !\is_string($value) || !\is_string($argument) || preg_match('#' . str_replace('#', '\#', $argument) . '#u', $value) === 1 ? null : $fail();

            case 'minLength':
            case 'maxLength':
                if (!\is_string($value) || !\is_int($argument)) {
                    return null;
                }
                $length = (int) preg_match_all('/./su', $value);

                return ($keyword === 'minLength' ? $length >= $argument : $length <= $argument) ? null : $fail();

            case 'minimum':
                return !\is_int($value) && !\is_float($value) || $value >= $argument ? null : $fail();

            case 'maximum':
                return !\is_int($value) && !\is_float($value) || $value <= $argument ? null : $fail();

            case 'format':
                if (!\is_string($value)) {
                    return null;
                }

                return match ($argument) {
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
                    'date-time' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1,
                    'uri' => filter_var($value, FILTER_VALIDATE_URL) !== false,
                    default => true,
                } ? null : $fail();

            case 'required':
                if (!$isObject || !\is_array($argument) || !\is_array($value)) {
                    return null;
                }
                foreach ($argument as $name) {
                    if (\is_string($name) && !\array_key_exists($name, $value)) {
                        return $fail($name);
                    }
                }

                return null;

            case 'properties':
                if (!$isObject || !\is_array($value)) {
                    return null;
                }
                foreach (Spec::map($argument) as $name => $subschema) {
                    if (\array_key_exists($name, $value) && \is_array($subschema)) {
                        $error = $this->validate($value[$name], $subschema, [...$path, $name]);
                        if ($error !== null) {
                            return $error;
                        }
                    }
                }

                return null;

            case 'additionalProperties':
                if (!$isObject || !\is_array($value)) {
                    return null;
                }
                $known = Spec::map($schema['properties'] ?? null);
                foreach ($value as $name => $item) {
                    if (\array_key_exists((string) $name, $known)) {
                        continue;
                    }
                    if ($argument === false) {
                        return $fail((string) $name);
                    }
                    if (\is_array($argument)) {
                        $error = $this->validate($item, $argument, [...$path, (string) $name]);
                        if ($error !== null) {
                            return $error;
                        }
                    }
                }

                return null;

            case 'propertyNames':
                if (!$isObject || !\is_array($value) || !\is_array($argument)) {
                    return null;
                }
                foreach (array_keys($value) as $name) {
                    if ($this->validate((string) $name, $argument, $path) !== null) {
                        return $fail((string) $name);
                    }
                }

                return null;

            case 'maxProperties':
                return !$isObject || !\is_array($value) || \count($value) <= $argument ? null : $fail();

            case 'items':
                if (!$isArray || !\is_array($value) || !\is_array($argument)) {
                    return null;
                }
                foreach ($value as $index => $item) {
                    $error = $this->validate($item, $argument, [...$path, (string) $index]);
                    if ($error !== null) {
                        return $error;
                    }
                }

                return null;

            case 'minItems':
                return !$isArray || !\is_array($value) || \count($value) >= $argument ? null : $fail();

            case 'maxItems':
                return !$isArray || !\is_array($value) || \count($value) <= $argument ? null : $fail();

            case 'uniqueItems':
                return !$isArray || !\is_array($value) || $argument !== true || \count(array_unique(array_map('serialize', $value))) === \count($value) ? null : $fail();

            case 'allOf':
                foreach (\is_array($argument) ? $argument : [] as $subschema) {
                    $error = \is_array($subschema) ? $this->validate($value, $subschema, $path) : null;
                    if ($error !== null) {
                        return $error;
                    }
                }

                return null;

            case 'oneOf':
            case 'anyOf':
                $matches = 0;
                foreach (\is_array($argument) ? $argument : [] as $subschema) {
                    if (\is_array($subschema) && $this->validate($value, $subschema, $path) === null) {
                        ++$matches;
                    }
                }

                return ($keyword === 'oneOf' ? $matches === 1 : $matches >= 1) ? null : $fail();

            default:
                return null; // annotations: description, example, default, …
        }
    }
}
