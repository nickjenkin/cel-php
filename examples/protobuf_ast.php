<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/_common.php';

use CEL\Ast;
use CEL\Environment;

$env = Environment::standard();
$ast = $env->compile('size(["a", "b"]) + 1');

$parsedExpr = $ast->toParsedExpr();
$checkedExpr = $ast->toCheckedExpr();
$imported = Ast::fromCheckedExpr($checkedExpr);

example_show('parsed json bytes', strlen($parsedExpr->serializeToJsonString()));
example_show('checked json bytes', strlen($checkedExpr->serializeToJsonString()));
example_show('type map entries', iterator_count($checkedExpr->getTypeMap()->getIterator()));
example_show('reference map entries', iterator_count($checkedExpr->getReferenceMap()->getIterator()));

$types = [];
foreach ($checkedExpr->getTypeMap() as $id => $type) {
    $types[] = $id . ':' . $type->serializeToJsonString();
    if (count($types) === 3) {
        break;
    }
}

$references = [];
foreach ($checkedExpr->getReferenceMap() as $id => $reference) {
    $references[] = $id . ':' . $reference->serializeToJsonString();
    if (count($references) === 3) {
        break;
    }
}

example_show('sample types', $types);
example_show('sample references', $references);
example_show('imported eval', $env->program($imported)->eval());
