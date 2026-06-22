<?php

declare(strict_types=1);

namespace CEL;

use CEL\Internal\Evaluator;
use CEL\Internal\ResidualBuilder;
use CEL\Proto\ProtoRegistry;

final class Program
{
    private readonly ProtoRegistry $protoRegistry;
    private readonly Evaluator $evaluator;
    private readonly RegexProvider $regexProvider;
    private ?ResidualBuilder $residualBuilder = null;

    /**
     * @param array<string, FunctionDeclaration> $functions
     */
    public function __construct(
        private readonly Ast $ast,
        private readonly array $functions,
        private readonly bool $macrosEnabled,
        private readonly ProgramOptions $options,
        ?ProtoRegistry $protoRegistry = null,
        private readonly bool $strongEnums = false,
        private readonly string $container = '',
        ?RegexProvider $regexProvider = null,
    ) {
        $this->protoRegistry = $protoRegistry ?? ProtoRegistry::standard();
        $this->regexProvider = $regexProvider ?? new PcreRegexProvider();
        $this->evaluator = new Evaluator($this->functions, $this->macrosEnabled, $this->options, $this->protoRegistry, $this->strongEnums, $this->container, $this->regexProvider);
    }

    /** @param array<string, mixed> $activation */
    public function eval(array $activation = []): mixed
    {
        return $this->evaluator->evaluate($this->ast->expr(), new EvaluationContext($activation));
    }

    /** @param array<string, mixed> $activation */
    public function evalPartial(array $activation = []): PartialResult
    {
        $context = new EvaluationContext($activation);
        try {
            $value = $this->evaluator->evaluate($this->ast->expr(), $context);
        } catch (EvaluationException $exception) {
            return PartialResult::fromError(ErrorValue::fromThrowable($exception), $this->residualExpression($context));
        }

        $unknown = $this->unknownFromValue($value);
        if ($unknown !== null) {
            return PartialResult::fromUnknown($unknown, $this->residualExpression($context));
        }

        return PartialResult::known($value);
    }

    private function unknownFromValue(mixed $value): ?UnknownValue
    {
        if ($value instanceof UnknownValue) {
            return $value;
        }

        if ($value instanceof OptionalValue && $value->hasValue()) {
            return $this->unknownFromValue($value->value());
        }

        if (is_array($value)) {
            $unknowns = [];
            foreach ($value as $item) {
                $unknown = $this->unknownFromValue($item);
                if ($unknown !== null) {
                    $unknowns[] = $unknown;
                }
            }

            return $unknowns === [] ? null : UnknownValue::merge($unknowns);
        }

        return null;
    }

    private function residualExpression(EvaluationContext $context): string
    {
        try {
            $this->residualBuilder ??= new ResidualBuilder($this->functions, $this->macrosEnabled, $this->options, $this->protoRegistry, $this->strongEnums, $this->container, $this->regexProvider);

            return $this->residualBuilder->residual($this->ast->expr(), $context);
        } catch (\Throwable) {
            return $this->ast->source();
        }
    }
}
