<?php

declare(strict_types=1);

namespace CEL;

use CEL\Internal\Expr;
use CEL\Internal\ProtoConverter;

class Ast
{
    public function __construct(
        private readonly Expr $expr,
        private readonly string $source,
        private readonly string $sourceDescription = '<input>',
    ) {
    }

    public function expr(): Expr
    {
        return $this->expr;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function sourceDescription(): string
    {
        return $this->sourceDescription;
    }

    public function toParsedExpr(): object
    {
        if (!class_exists(\CEL\Generated\Expr\ParsedExpr::class)) {
            throw new UnsupportedFeatureException('generated protobuf classes are not available; run composer proto:generate');
        }

        return (new ProtoConverter())->toParsedExpr($this->expr, $this->source, $this->sourceDescription);
    }

    public static function fromParsedExpr(object $expr): self
    {
        if (!class_exists(\CEL\Generated\Expr\ParsedExpr::class)) {
            throw new UnsupportedFeatureException('generated protobuf classes are not available; run composer proto:generate');
        }
        if (!$expr instanceof \CEL\Generated\Expr\ParsedExpr) {
            throw new \InvalidArgumentException(sprintf('expected %s, got %s', \CEL\Generated\Expr\ParsedExpr::class, $expr::class));
        }

        $converter = new ProtoConverter();

        $sourceDescription = $converter->sourceLocationFromParsedExpr($expr);

        return new self($converter->fromParsedExpr($expr), $sourceDescription, $sourceDescription);
    }

    public static function fromCheckedExpr(object $expr): self
    {
        if (!class_exists(\CEL\Generated\Expr\CheckedExpr::class)) {
            throw new UnsupportedFeatureException('generated protobuf classes are not available; run composer proto:generate');
        }
        if (!$expr instanceof \CEL\Generated\Expr\CheckedExpr) {
            throw new \InvalidArgumentException(sprintf('expected %s, got %s', \CEL\Generated\Expr\CheckedExpr::class, $expr::class));
        }

        $converter = new ProtoConverter();

        $sourceDescription = $converter->sourceLocationFromParsedExpr($expr);

        return new self($converter->fromCheckedExpr($expr), $sourceDescription, $sourceDescription);
    }
}
