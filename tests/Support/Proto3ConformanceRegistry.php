<?php

declare(strict_types=1);

namespace CEL\Tests\Support;

use CEL\Proto\ProtoRegistry;

final class Proto3ConformanceRegistry
{
    public static function create(): ProtoRegistry
    {
        return ProtoRegistry::standard()
            ->registerMessage('cel.expr.conformance.proto3.TestAllTypes', \CEL\Generated\Expr\Conformance\Proto3\TestAllTypes::class, ['TestAllTypes'])
            ->registerMessage('cel.expr.conformance.proto3.NestedTestAllTypes', \CEL\Generated\Expr\Conformance\Proto3\NestedTestAllTypes::class, ['NestedTestAllTypes'])
            ->registerMessage('cel.expr.conformance.proto3.TestAllTypes.NestedMessage', \CEL\Generated\Expr\Conformance\Proto3\TestAllTypes\NestedMessage::class, ['TestAllTypes.NestedMessage', 'NestedMessage'])
            ->registerEnum('cel.expr.conformance.proto3.GlobalEnum', \CEL\Generated\Expr\Conformance\Proto3\GlobalEnum::class, ['GlobalEnum'])
            ->registerEnum('cel.expr.conformance.proto3.TestAllTypes.NestedEnum', \CEL\Generated\Expr\Conformance\Proto3\TestAllTypes\NestedEnum::class, ['TestAllTypes.NestedEnum', 'NestedEnum']);
    }
}
