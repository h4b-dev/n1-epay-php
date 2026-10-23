<?php

declare(strict_types=1);

namespace N1co\EPay\Internal;

/**
 * Reads typed fields from a decoded JSON object for the models' fromArray(). Throws
 * \UnexpectedValueException (never a \TypeError) when a field is missing or has another type;
 * the SDK turns it into a ServiceException or a WebhookSignatureException.
 *
 * @internal
 */
final class Reader
{
    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(
        private readonly array $data,
        private readonly string $path,
    ) {}

    public static function of(#[\SensitiveParameter] mixed $data, string $path): self
    {
        if (!\is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new \UnexpectedValueException(\sprintf('%s is not an object.', $path));
        }

        return new self($data, $path);
    }

    /**
     * Keeps the data (which may hold tokens) out of var_dump(), print_r() and traces.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['path' => $this->path];
    }

    public function string(string $key): string
    {
        $value = $this->value($key);
        if (!\is_string($value)) {
            throw $this->invalid($key, 'a string');
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->value($key);
        if ($value !== null && !\is_string($value)) {
            throw $this->invalid($key, 'a string or null');
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->value($key);
        if (!\is_int($value)) {
            throw $this->invalid($key, 'an integer');
        }

        return $value;
    }

    public function nullableInt(string $key): ?int
    {
        $value = $this->value($key);
        if ($value !== null && !\is_int($value)) {
            throw $this->invalid($key, 'an integer or null');
        }

        return $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->value($key);
        if (!\is_bool($value)) {
            throw $this->invalid($key, 'a boolean');
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $value = $this->value($key);
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->invalid($key, 'an object of strings');
        }
        $map = [];
        foreach ($value as $name => $item) {
            if (!\is_string($item)) {
                throw $this->invalid($key, 'an object of strings');
            }
            $map[(string) $name] = $item;
        }

        return $map;
    }

    /**
     * @return list<string>|null
     */
    public function nullableStringList(string $key): ?array
    {
        $value = $this->value($key);
        if ($value === null) {
            return null;
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, 'an array of strings or null');
        }
        foreach ($value as $item) {
            if (!\is_string($item)) {
                throw $this->invalid($key, 'an array of strings or null');
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        $value = $this->value($key);
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->invalid($key, 'an array');
        }

        return $value;
    }

    /** A nested object, for the nested model's fromArray(). */
    public function object(string $key): self
    {
        return self::of($this->value($key), $this->path . '.' . $key);
    }

    public function nullableObject(string $key): ?self
    {
        $value = $this->value($key);

        return $value === null ? null : self::of($value, $this->path . '.' . $key);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->data);
    }

    public function raw(string $key): mixed
    {
        return $this->value($key);
    }

    /**
     * The keys the model does not know, kept for forward compatibility.
     *
     * @param list<string> $known
     *
     * @return array<string, mixed>
     */
    public function extra(array $known): array
    {
        $extra = [];
        foreach ($this->data as $key => $value) {
            if (!\in_array((string) $key, $known, true)) {
                $extra[(string) $key] = $value;
            }
        }

        return $extra;
    }

    public function path(): string
    {
        return $this->path;
    }

    private function value(string $key): mixed
    {
        if (!\array_key_exists($key, $this->data)) {
            throw new \UnexpectedValueException(\sprintf('%s.%s is missing.', $this->path, $key));
        }

        return $this->data[$key];
    }

    private function invalid(string $key, string $expected): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf('%s.%s is not %s.', $this->path, $key, $expected));
    }
}
