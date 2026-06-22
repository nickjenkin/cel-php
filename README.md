# CEL PHP

[Common Expression Language (CEL)](https://cel.dev/) for PHP.

This package provides a PHP 8.2+ SDK for parsing, checking, and evaluating CEL expressions in-process. It follows the familiar CEL flow used by other SDKs: create an environment, compile an expression, build a reusable program, and evaluate it with activation data.

CEL is useful when an application needs user-provided or configuration-provided expressions without exposing a general-purpose programming language.

## Installation

Install the package into an application with Composer:

```sh
composer require nickjenkin/cel-php
```

Runtime requirements:

- PHP `^8.2`
- PHP extensions: `bcmath`, `json`, `mbstring`

Composer installs the runtime `google/protobuf` dependency automatically.

## Status

The current release supports:

- Scalar, list, and map literals
- Variables declared in an environment
- Boolean, arithmetic, comparison, membership, conditional, selection, and index expressions
- Standard macros: `has`, `all`, `exists`, `exists_one`, `existsOne`, `filter`, `map`, `transformList`, and `transformMap`, including CEL comprehension v2 index/key-plus-value forms
- Common functions and conversions such as `size`, `type`, `int`, `uint`, `double`, `string`, `bytes`, `duration`, and `timestamp`
- String receiver functions such as `startsWith`, `endsWith`, `contains`, and `matches`
- Extension libraries for `cel.bind`, `base64.encode` / `base64.decode`, `math.*`, `strings.quote`, `net.IP` / `net.CIDR`, and string receiver helpers such as `format`, `split`, `replace`, and `reverse`
- CEL conformance support for the test-only `cel.block`, `cel.index`, `cel.iterVar`, and `cel.accuVar` block extension
- Initial partial evaluation with unknown attributes, captured runtime errors, and residual expression strings
- Custom global and receiver-style functions
- Proto3 generated message construction and field selection for registered types
- Proto3 optional presence, oneofs, repeated fields, maps, enums, wrappers, `Any`, `Struct`, `Value`, `ListValue`, timestamps, and durations

This project is scoped to CEL core plus protobuf **proto3** support. Proto2 generated classes, proto2 field-presence semantics, and proto2 extension fields are intentionally excluded and reported as explicit conformance skips.

The current release does not yet implement every CEL standard-library edge case or the full CEL residual evaluation model.

## Development Setup

For work on this repository, install development dependencies from the checkout:

```sh
composer install
```

`buf` and `protoc` are only required when regenerating protobuf classes or conformance fixtures with `composer proto:generate` / `composer proto:fixtures`.

## Quick Start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use CEL\Environment;
use CEL\Type;

$env = Environment::builder()
    ->variable('name', Type::string())
    ->variable('group', Type::string())
    ->build();

$ast = $env->compile('name.startsWith("/groups/" + group)');
$program = $env->program($ast);

$result = $program->eval([
    'name' => '/groups/acme.co/documents/secret',
    'group' => 'acme.co',
]);

var_dump($result); // true
```

## Core API

The main entrypoint is `CEL\Environment`.

```php
$env = Environment::builder()
    ->variable('request', Type::map(Type::string(), Type::dyn()))
    ->variable('roles', Type::list(Type::string()))
    ->build();

$checkedAst = $env->compile('"admin" in roles && request.path.startsWith("/admin")');
$program = $env->program($checkedAst);

$allowed = $program->eval([
    'roles' => ['admin'],
    'request' => ['path' => '/admin/settings'],
]);
```

Use `Environment::standard()` for expressions that do not need declared variables:

```php
$env = Environment::standard();
$result = $env->program($env->compile('1 + 2 * 3 == 7'))->eval();
```

## Resource Limits

Programs are evaluated with `CEL\ProgramOptions`. The defaults are `maxSteps: 10000` and `maxDepth: 100`; pass tighter limits when evaluating expressions from less-trusted sources or tighter runtime budgets.

```php
use CEL\Environment;
use CEL\ProgramOptions;

$env = Environment::standard();
$program = $env->program(
    $env->compile('[1, 2, 3].all(x, x > 0)'),
    new ProgramOptions(maxSteps: 1000, maxDepth: 50),
);

$result = $program->eval();
```

## Partial Evaluation

Use `Program::evalPartial()` when some input values are not available yet. The partial result is either known, or it includes the unknown attributes and a residual CEL expression to evaluate later.

```php
use CEL\Environment;
use CEL\Type;
use CEL\UnknownValue;

$env = Environment::builder()
    ->variable('request', Type::dyn())
    ->build();

$program = $env->program($env->compile('request.user == "nick"'));

$partial = $program->evalPartial([
    'request' => UnknownValue::attribute('request'),
]);

var_dump($partial->isKnown()); // false
var_dump($partial->unknown()?->attributes()); // ["request.user"]
var_dump($partial->residualExpression()); // request.user == "nick"
```

## Types

Declare variables before compiling expressions. The checker uses these declarations to catch missing names and obvious type errors early.

```php
use CEL\Type;

Type::dyn();
Type::bool();
Type::int();
Type::uint();
Type::double();
Type::string();
Type::bytes();
Type::null();
Type::type();
Type::list(Type::string());
Type::map(Type::string(), Type::dyn());
```

Use `Type::dyn()` when the exact runtime shape is intentionally flexible, such as associative arrays from decoded JSON.

## Values

Most CEL values map directly to PHP values:

- CEL `bool` to PHP `bool`
- CEL `int` to PHP `int`
- CEL `double` to PHP `float`
- CEL `string` to PHP `string`
- CEL `null` to PHP `null`
- CEL `list` to PHP list arrays
- CEL `map` to PHP associative arrays

Some CEL values use wrappers:

- `CEL\UInt` for uint64 values
- `CEL\Bytes` for byte strings
- `CEL\DurationValue` for durations
- `CEL\TimestampValue` for timestamps
- `CEL\Type` for CEL type values

Example:

```php
$value = Environment::standard()
    ->program(Environment::standard()->compile('uint(1) + 2u'))
    ->eval();

echo $value->value(); // 3
```

## Macros

CEL macros are enabled by default.

```php
$env = Environment::builder()
    ->variable('users', Type::list(Type::dyn()))
    ->build();

$program = $env->program($env->compile('users.filter(u, u.active).map(u, u.email)'));

$emails = $program->eval([
    'users' => [
        ['email' => 'alice@example.test', 'active' => true],
        ['email' => 'bob@example.test', 'active' => false],
    ],
]);
```

Disable macros if an embedding needs a smaller expression surface:

```php
$env = Environment::builder()
    ->disableMacros()
    ->build();
```

## Regex Provider

CEL `matches()` uses a configurable regex provider. PCRE is the default, while the CEL spec defines regex matching in terms of RE2:

```php
$env = Environment::standard();
$result = $env->program($env->compile('"abc123".matches("[a-z]+[0-9]+")'))->eval();
```

If the optional [`re2` PHP extension](https://github.com/nickjenkin/re2-php) is installed, opt in to RE2 per environment:

```php
$env = Environment::builder()
    ->useRe2Regex()
    ->build();
```

Applications can also provide a custom `CEL\RegexProvider` implementation with `Environment::builder()->regexProvider($provider)`.

## Custom Functions

Register application functions on the environment builder.

```php
use CEL\EvaluationContext;
use CEL\FunctionDeclaration;
use CEL\Overload;

$env = Environment::builder()
    ->variable('email', Type::string())
    ->function(new FunctionDeclaration('isAllowedDomain', [
        new Overload(
            id: 'is_allowed_domain_string',
            argumentTypes: [Type::string()],
            resultType: Type::bool(),
            implementation: static function (array $args, EvaluationContext $context): bool {
                return str_ends_with($args[0], '@example.test');
            },
        ),
    ]))
    ->build();

$program = $env->program($env->compile('isAllowedDomain(email)'));
$allowed = $program->eval(['email' => 'alice@example.test']);
```

## Protobuf Interop

The repository vendors pinned CEL spec protos under `proto/cel-spec` and generates PHP classes under `src/Generated`. Generation is proto3-only for conformance protos; the Composer script excludes `cel/expr/conformance/proto2`. The same script also appends the example application protos under `proto/examples` so protobuf examples are runnable.

Regenerate protobuf classes:

```sh
composer proto:generate
composer dump-autoload
```

Convert selected upstream conformance fixtures from textproto to JSON:

```sh
composer proto:fixtures
```

Export supported ASTs to generated protobuf objects and import generated protobuf ASTs back into executable SDK ASTs:

```php
use CEL\Ast;
use CEL\Environment;

$ast = Environment::standard()->compile('1 + 2 * 3');

$parsedExpr = $ast->toParsedExpr();
$checkedExpr = $ast->toCheckedExpr();

$importedParsed = Ast::fromParsedExpr($parsedExpr);
$importedChecked = Ast::fromCheckedExpr($checkedExpr);

echo $parsedExpr->serializeToJsonString();
```

Generated classes use the project-prefixed protobuf namespace, for example `CEL\Generated\Expr\ParsedExpr`. Imported protobuf ASTs preserve executable expression structure and source positions where present; protobuf messages do not contain the original CEL source text, so imported `Ast::source()` uses the protobuf source location.

### Well-Known Protobuf Types

Google well-known protobuf types are registered automatically in standard environments:

```php
use CEL\Environment;

$env = Environment::standard();

$result = $env
    ->program($env->compile('google.protobuf.Timestamp{seconds: 1577934245}.seconds'))
    ->eval();
```

### Application Protobuf Messages

Register application generated protobuf message and enum classes before compiling expressions that construct or reference those types:

```php
$env = Environment::builder()
    ->messageType('acme.policy.Request', Acme\Policy\Request::class, ['Request'])
    ->enumType('acme.policy.Decision', Acme\Policy\Decision::class, ['Decision'])
    ->build();
```

The runtime uses generated PHP protobuf methods for field access and presence. This covers proto3 optional fields via `hasX()`, oneofs, repeated fields, map fields, enum constants, wrapper unboxing, and well-known types including `Any`, `Struct`, `Value`, `ListValue`, `Timestamp`, and `Duration`. CEL conformance fixture types such as `cel.expr.conformance.proto3.TestAllTypes` are test-only and are not registered in normal SDK environments.

## Examples

Runnable examples live in `examples/`. The full capability index is in `examples/README.md`; run everything with:

```sh
composer proto:generate
php examples/run_all.php
```

The examples cover basic evaluation, typed activations, operators and CEL values, compile-time/runtime errors, macros, partial evaluation, optionals, standard functions, extension libraries, custom global and receiver functions, well-known protobuf types, application protobuf registration with `messageType()` / `enumType()`, and protobuf AST round trips.

## Benchmarking

Core performance benchmarks live in `benchmarks/` and use PHPBench. They are intentionally separate from PHPUnit and the conformance harness, and they do not run as part of `composer test`.

Run a short smoke benchmark after changing benchmark code or local setup:

```sh
composer bench:quick
```

Run the normal benchmark suite when you want more stable local measurements:

```sh
composer bench
```

The benchmark suite is core-only: it covers environment construction, parse, check, compile, precompiled evaluation, macros, optionals, extension functions, partial evaluation, residual generation, and core AST protobuf round trips. It does not use application protobuf messages, generated conformance fixtures, or proto3 message/WKT benchmarks.

Benchmark results are informational. There are no timing assertions or performance budgets in the default scripts, so normal correctness checks should continue to use PHPUnit and conformance:

```sh
composer test
composer conformance
```

## Fuzzing

The repository includes an opt-in coverage-guided fuzz target for the CEL parser, checker, and evaluator using `nikic/php-fuzzer`. It is a development dependency and does not run as part of `composer test`.

Run the fuzzer against a working corpus initialized from the checked-in seeds:

```sh
composer fuzz
```

Seed expressions live in `tools/fuzz/seeds/`. The mutable working corpus lives in `var/fuzz-corpus/`, and rerunning the same command resumes from that corpus. The target allows normal CEL parse, check, unsupported-feature, and evaluation exceptions, but records PHP errors, warnings, unexpected throwables, and timeouts as crashes.

When a crash is found, minimize and replay it with:

```sh
vendor/bin/php-fuzzer minimize-crash tools/fuzz/cel-target.php crash-HASH.txt
composer fuzz:single -- minimized-HASH.txt
```

Generate an HTML coverage report for the current corpus with:

```sh
composer fuzz:coverage
```

## Testing

Run the test suite and conformance fixture conversion:

```sh
composer validate --strict
composer proto:generate
composer proto:fixtures
composer test
composer conformance
```

The conformance harness classifies cases as:

- `pass`
- `fail`
- `skip_proto2`
- `skip_unsupported_extension`

Proto2 fixture cases are expected skips, including the proto2 extension fixture. `composer conformance` exits non-zero for unexpected failures or unsupported non-proto2 extension skips. The checked-in fixture set includes core smoke fixtures plus parser grammar, conformance plumbing, proto3, wrappers, fields, enums, dynamic, optionals, timestamps, conversions, namespace resolution, type-deduction, comprehension v2 macros, block and network extensions, unknowns, proto2, proto2 extensions, and extension-library fixtures.

## Current Limitations

- Proto2 is intentionally out of scope: no proto2 generated classes, no proto2 conformance target, no proto2 extension fields.
- Partial evaluation minimizes common expression forms, but it is not yet a complete CEL residual AST planner for every macro, function overload, and protobuf edge case.
- `matches` uses PHP PCRE by default even though the CEL spec defines regex matching in terms of RE2. It can use RE2 when an environment is built with `useRe2Regex()` and the optional [`re2` PHP extension](https://github.com/nickjenkin/re2-php) is installed.
- Integer evaluation is bounded by CEL/PHP int64 behavior; uint64 values are represented by `CEL\UInt`.
- Checked protobuf export includes the expression tree, source info, and basic type/reference maps. It is not a complete CEL checker replacement yet.
- Protobuf AST import supports executable expression trees, including lowered comprehension nodes. It does not reconstruct the original CEL source text because protobuf AST messages do not carry it.

## License

Apache-2.0
