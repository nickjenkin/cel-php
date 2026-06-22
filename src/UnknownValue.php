<?php

declare(strict_types=1);

namespace CEL;

final readonly class UnknownValue implements \Stringable
{
    /** @param non-empty-list<string> $attributes */
    private function __construct(private array $attributes)
    {
    }

    public static function attribute(string $name): self
    {
        if ($name === '') {
            throw new \InvalidArgumentException('unknown attribute name cannot be empty');
        }

        return new self([$name]);
    }

    /** @param iterable<self> $unknowns */
    public static function merge(iterable $unknowns): self
    {
        $attributes = [];
        foreach ($unknowns as $unknown) {
            foreach ($unknown->attributes as $attribute) {
                $attributes[$attribute] = true;
            }
        }

        if ($attributes === []) {
            throw new \InvalidArgumentException('cannot merge an empty unknown set');
        }

        return new self(array_keys($attributes));
    }

    public function select(string $field): self
    {
        return new self(array_map(
            static fn (string $attribute): string => $attribute . '.' . $field,
            $this->attributes,
        ));
    }

    public function index(mixed $index): self
    {
        $segment = match (true) {
            is_int($index), is_float($index), is_bool($index) => '[' . json_encode($index, JSON_THROW_ON_ERROR) . ']',
            $index instanceof UInt => '[' . $index->value() . 'u]',
            is_string($index) => '[' . json_encode($index, JSON_THROW_ON_ERROR) . ']',
            default => '[?]',
        };

        return new self(array_map(
            static fn (string $attribute): string => $attribute . $segment,
            $this->attributes,
        ));
    }

    /** @return non-empty-list<string> */
    public function attributes(): array
    {
        return $this->attributes;
    }

    public function __toString(): string
    {
        return 'unknown(' . implode(', ', $this->attributes) . ')';
    }
}
