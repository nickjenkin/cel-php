<?php

declare(strict_types=1);

namespace CEL;

final class EvaluationContext
{
    /** @param array<string, mixed> $activation */
    public function __construct(
        private readonly array $activation,
        /** @var array<string, mixed> */
        private array $locals = [],
    )
    {
    }

    public function has(string $name): bool
    {
        return $this->hasLocal($name) || $this->hasActivation($name);
    }

    public function get(string $name): mixed
    {
        if ($this->hasLocal($name)) {
            return $this->locals[$name];
        }
        if ($this->hasActivation($name)) {
            return $this->activation[$name];
        }

        throw new EvaluationException(sprintf('undeclared reference to "%s"', $name));
    }

    public function hasActivation(string $name): bool
    {
        return array_key_exists($name, $this->activation);
    }

    public function getActivation(string $name): mixed
    {
        if (!$this->hasActivation($name)) {
            throw new EvaluationException(sprintf('undeclared reference to "%s"', $name));
        }

        return $this->activation[$name];
    }

    public function hasLocal(string $name): bool
    {
        return array_key_exists($name, $this->locals);
    }

    public function getLocal(string $name): mixed
    {
        if (!$this->hasLocal($name)) {
            throw new EvaluationException(sprintf('undeclared reference to "%s"', $name));
        }

        return $this->locals[$name];
    }

    public function with(string $name, mixed $value): self
    {
        $next = clone $this;
        $next->locals[$name] = $value;

        return $next;
    }

    /** @param array<string, mixed> $locals */
    public function withMany(array $locals): self
    {
        $next = clone $this;
        foreach ($locals as $name => $value) {
            $next->locals[$name] = $value;
        }

        return $next;
    }
}
