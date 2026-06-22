<?php

declare(strict_types=1);

use CEL\Bytes;
use CEL\DurationValue;
use CEL\Environment;
use CEL\ErrorValue;
use CEL\OptionalValue;
use CEL\TimestampValue;
use CEL\UInt;
use CEL\UnknownValue;
use Google\Protobuf\Internal\MapField;
use Google\Protobuf\Internal\Message;
use Google\Protobuf\Internal\RepeatedField;

function example_eval(Environment $env, string $expression, array $activation = []): mixed
{
    return $env->program($env->compile($expression))->eval($activation);
}

function example_show(string $label, mixed $value): void
{
    echo str_pad($label, 32), example_format($value), PHP_EOL;
}

function example_format(mixed $value): string
{
    if ($value instanceof Bytes) {
        return 'b"' . addcslashes($value->raw(), "\0..\37\177..\377\\\"") . '"';
    }

    if ($value instanceof UInt || $value instanceof TimestampValue || $value instanceof DurationValue || $value instanceof UnknownValue || $value instanceof ErrorValue || $value instanceof OptionalValue) {
        return (string) $value;
    }

    if ($value instanceof RepeatedField || $value instanceof MapField) {
        return example_format(iterator_to_array($value));
    }

    if ($value instanceof Message) {
        return $value::class . ' ' . $value->serializeToJsonString();
    }

    if (is_array($value)) {
        return json_encode(example_json_value($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    if (is_object($value) && method_exists($value, '__toString')) {
        return (string) $value;
    }

    return var_export($value, true);
}

function example_json_value(mixed $value): mixed
{
    if ($value instanceof Bytes || $value instanceof UInt || $value instanceof TimestampValue || $value instanceof DurationValue || $value instanceof UnknownValue || $value instanceof ErrorValue || $value instanceof OptionalValue) {
        return (string) $value;
    }

    if ($value instanceof RepeatedField || $value instanceof MapField) {
        return example_json_value(iterator_to_array($value));
    }

    if ($value instanceof Message) {
        return $value->serializeToJsonString();
    }

    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = example_json_value($item);
        }

        return $out;
    }

    if (is_object($value) && method_exists($value, '__toString')) {
        return (string) $value;
    }

    return $value;
}
