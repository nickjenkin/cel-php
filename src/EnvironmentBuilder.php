<?php

declare(strict_types=1);

namespace CEL;

use CEL\Proto\ProtoRegistry;

final class EnvironmentBuilder
{
    /** @var array<string, Type> */
    private array $variables = [];

    /** @var array<string, FunctionDeclaration> */
    private array $functions = [];

    private bool $macrosEnabled = true;

    private bool $strongEnums = false;

    private string $container = '';

    private ProtoRegistry $protoRegistry;

    private RegexProvider $regexProvider;

    public function __construct()
    {
        $this->protoRegistry = ProtoRegistry::standard();
        $this->regexProvider = new PcreRegexProvider();
    }

    public function variable(string $name, Type $type): self
    {
        $this->variables[$name] = $type;

        return $this;
    }

    public function function(FunctionDeclaration $declaration): self
    {
        $this->functions[$declaration->name] = $declaration;

        return $this;
    }

    public function protoRegistry(ProtoRegistry $registry): self
    {
        $this->protoRegistry = $registry;

        return $this;
    }

    public function enableStrongEnums(): self
    {
        $this->strongEnums = true;

        return $this;
    }

    public function container(string $container): self
    {
        $this->container = trim($container, '.');

        return $this;
    }

    /** @param list<string> $aliases */
    public function messageType(string $protoName, string $className, array $aliases = []): self
    {
        $this->protoRegistry->registerMessage($protoName, $className, $aliases);

        return $this;
    }

    /** @param list<string> $aliases */
    public function enumType(string $protoName, string $className, array $aliases = []): self
    {
        $this->protoRegistry->registerEnum($protoName, $className, $aliases);

        return $this;
    }

    public function disableMacros(): self
    {
        $this->macrosEnabled = false;

        return $this;
    }

    public function regexProvider(RegexProvider $provider): self
    {
        $this->regexProvider = $provider;

        return $this;
    }

    public function useRe2Regex(): self
    {
        $this->regexProvider = new Re2RegexProvider();

        return $this;
    }

    public function build(): Environment
    {
        return new Environment($this->variables, $this->functions, $this->macrosEnabled, $this->protoRegistry, $this->strongEnums, $this->container, $this->regexProvider);
    }
}
