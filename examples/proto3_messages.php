<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Environment;

$env = Environment::standard();

$timestamp = $env
    ->program($env->compile('google.protobuf.Timestamp{seconds: 1577934245}.seconds'))
    ->eval();
$structName = $env
    ->program($env->compile('google.protobuf.Struct{fields: {"name": google.protobuf.Value{string_value: "ready"}}}.name'))
    ->eval();
$listValue = $env
    ->program($env->compile('google.protobuf.ListValue{values: [google.protobuf.Value{string_value: "first"}]}[0]'))
    ->eval();
$wrapper = $env
    ->program($env->compile('google.protobuf.Int64Value{value: 99} == 99'))
    ->eval();
$any = $env
    ->program($env->compile('google.protobuf.Any{type_url: "type.googleapis.com/acme.Message", value: b""}'))
    ->eval();
$nullValue = $env
    ->program($env->compile('google.protobuf.Value{null_value: google.protobuf.NullValue.NULL_VALUE}'))
    ->eval();

example_show('Timestamp literal', $timestamp);
example_show('Struct field select', $structName);
example_show('ListValue index', $listValue);
example_show('wrapper comparison', $wrapper);
example_show('Any literal', $any instanceof Google\Protobuf\Any);
example_show('NullValue literal', $nullValue);
