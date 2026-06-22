# Examples

Run examples from the repository root after installing dependencies and generating protobuf classes:

```sh
composer install
composer proto:generate
php examples/run_all.php
```

| Capability | File | Run |
| --- | --- | --- |
| Basic parsing, checking, and evaluation | `basic_eval.php` | `php examples/basic_eval.php` |
| Typed variables and activations | `typed_variables.php` | `php examples/typed_variables.php` |
| Literals, operators, lists, maps, bytes, uints, `type()`, and PHP result values | `operators_and_values.php` | `php examples/operators_and_values.php` |
| Compile-time errors, missing identifiers, runtime errors, and resource limits | `checking_and_errors.php` | `php examples/checking_and_errors.php` |
| Comprehension macros over PHP arrays | `macros.php` | `php examples/macros.php` |
| Optional values, optional select/index, optional list/map entries, and fallback methods | `optionals.php` | `php examples/optionals.php` |
| Partial evaluation, unknowns, short-circuiting, residual expressions, and captured errors | `partial_evaluation.php` | `php examples/partial_evaluation.php` |
| Standard conversions, string functions, regex, timestamps, durations, and time arithmetic | `standard_functions.php` | `php examples/standard_functions.php` |
| Extension libraries: `cel.bind`, `base64`, `math`, `strings`, receiver string helpers, and `net` types | `extensions.php` | `php examples/extensions.php` |
| Minimal global custom function | `custom_function.php` | `php examples/custom_function.php` |
| Global and receiver-style custom functions | `custom_functions.php` | `php examples/custom_functions.php` |
| Built-in protobuf well-known types without application registration | `proto3_messages.php` | `php examples/proto3_messages.php` |
| Application protobuf messages/enums with `messageType()` and `enumType()` | `application_protobuf.php` | `php examples/application_protobuf.php` |
| `ParsedExpr` / `CheckedExpr` round trips with compact type/reference map output | `protobuf_ast.php` | `php examples/protobuf_ast.php` |
| Execute every example and stop on the first failure | `run_all.php` | `php examples/run_all.php` |

The application protobuf example uses `proto/examples/cel/examples/policy.proto`, generated into `CEL\Generated\Examples\Policy`.
