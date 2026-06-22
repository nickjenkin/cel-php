<?php

declare(strict_types=1);

namespace CEL;

final class Overload
{
    /**
     * @param list<Type> $argumentTypes
     * @param callable(list<mixed>, EvaluationContext): mixed $implementation
     * @param list<object> $protoArgumentTypes
     */
    public function __construct(
        public readonly string $id,
        public readonly array $argumentTypes,
        public readonly Type $resultType,
        public readonly mixed $implementation,
        public readonly bool $receiverStyle = false,
        public readonly array $protoArgumentTypes = [],
        public readonly ?object $protoResultType = null,
    ) {
    }
}
