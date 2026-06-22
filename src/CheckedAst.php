<?php

declare(strict_types=1);

namespace CEL;

use CEL\Internal\Expr;
use CEL\Internal\ProtoConverter;
use CEL\Proto\ProtoRegistry;

final class CheckedAst extends Ast
{
    private readonly ProtoRegistry $protoRegistry;

    /** @param array<string, Type> $variables */
    public function __construct(
        Expr $expr,
        string $source,
        private readonly Type $resultType,
        private readonly array $variables,
        ?ProtoRegistry $protoRegistry = null,
        private readonly array $functions = [],
        private readonly string $container = '',
        string $sourceDescription = '<input>',
    ) {
        parent::__construct($expr, $source, $sourceDescription);
        $this->protoRegistry = $protoRegistry ?? ProtoRegistry::standard();
    }

    public function resultType(): Type
    {
        return $this->resultType;
    }

    /** @return array<string, Type> */
    public function variables(): array
    {
        return $this->variables;
    }

    public function toCheckedExpr(): object
    {
        if (!class_exists(\CEL\Generated\Expr\CheckedExpr::class)) {
            throw new UnsupportedFeatureException('generated protobuf classes are not available; run composer proto:generate');
        }

        return (new ProtoConverter())->toCheckedExpr($this->expr(), $this->source(), $this->variables, $this->protoRegistry, $this->functions, $this->container, $this->sourceDescription());
    }
}
