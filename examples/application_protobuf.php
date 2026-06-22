<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Generated\Examples\Policy\Decision;
use CEL\Generated\Examples\Policy\Request;
use CEL\Environment;

$env = Environment::builder()
    ->messageType('cel.examples.policy.Request', Request::class, ['Request'])
    ->enumType('cel.examples.policy.Decision', Decision::class, ['Decision'])
    ->build();

$literal = <<<'CEL'
Request{
  user: "alice@example.test",
  amount_cents: 1299,
  vip: true,
  scopes: ["read", "approve"],
  labels: {"tier": "gold", "team": "risk"},
  requested_at: timestamp("2024-01-02T03:04:05Z"),
  decision: Decision.ALLOW,
  region: "AU",
  account_id: "acct-123"
}
CEL;

$request = example_eval($env, $literal);
example_show('message class', $request instanceof Request);
example_show('constructed message', $request);
example_show('field selection', example_eval($env, $literal . '.user'));
example_show('enum constant', example_eval($env, $literal . '.decision == Decision.ALLOW'));
example_show('optional presence', example_eval($env, 'has(' . $literal . '.region)'));
example_show('oneof presence', example_eval($env, 'has(' . $literal . '.account_id)'));
example_show('repeated field', example_eval($env, $literal . '.scopes[1]'));
example_show('map field', example_eval($env, $literal . '.labels["tier"]'));
example_show('timestamp field', example_eval($env, $literal . '.requested_at.getFullYear()'));
example_show('php enum name', Decision::name($request->getDecision()));
