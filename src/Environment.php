<?php

declare(strict_types=1);

namespace CEL;

use CEL\Internal\Checker;
use CEL\Internal\DiagnosticFormatter;
use CEL\Internal\Parser;
use CEL\Proto\ProtoRegistry;

final class Environment
{
    private readonly ProtoRegistry $protoRegistry;
    private readonly RegexProvider $regexProvider;

    /**
     * @param array<string, Type> $variables
     * @param array<string, FunctionDeclaration> $functions
     */
    public function __construct(
        private readonly array $variables = [],
        private readonly array $functions = [],
        private readonly bool $macrosEnabled = true,
        ?ProtoRegistry $protoRegistry = null,
        private readonly bool $strongEnums = false,
        private readonly string $container = '',
        ?RegexProvider $regexProvider = null,
    ) {
        $this->protoRegistry = $protoRegistry ?? ProtoRegistry::standard();
        $this->regexProvider = $regexProvider ?? new PcreRegexProvider();
    }

    public static function builder(): EnvironmentBuilder
    {
        return new EnvironmentBuilder();
    }

    public static function standard(): self
    {
        return self::builder()->build();
    }

    public function parse(string $source, string $sourceDescription = '<input>'): Ast
    {
        return new Ast((new Parser($source, $sourceDescription))->parse(), $source, $sourceDescription);
    }

    public function check(Ast $ast): CheckedAst
    {
        try {
            $type = (new Checker($this->variables, $this->functions, $this->protoRegistry, $this->strongEnums, $this->container))->check($ast->expr());
        } catch (CheckException $exception) {
            throw new CheckException(
                DiagnosticFormatter::formatExceptionMessage($exception->getMessage(), $ast->source(), $ast->sourceDescription()),
                0,
                $exception,
            );
        }

        return new CheckedAst($ast->expr(), $ast->source(), $type, $this->variables, $this->protoRegistry, $this->functions, $this->container, $ast->sourceDescription());
    }

    public function compile(string $source, string $sourceDescription = '<input>'): CheckedAst
    {
        return $this->check($this->parse($source, $sourceDescription));
    }

    public function program(Ast $ast, ?ProgramOptions $options = null): Program
    {
        return new Program($ast, $this->functions, $this->macrosEnabled, $options ?? new ProgramOptions(), $this->protoRegistry, $this->strongEnums, $this->container, $this->regexProvider);
    }
}
