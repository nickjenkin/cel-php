<?php

declare(strict_types=1);

namespace CEL\Internal;

use CEL\ParseException;

final class Parser
{
    private const RESERVED = [
        'as' => true,
        'break' => true,
        'const' => true,
        'continue' => true,
        'else' => true,
        'for' => true,
        'function' => true,
        'if' => true,
        'import' => true,
        'let' => true,
        'loop' => true,
        'package' => true,
        'namespace' => true,
        'return' => true,
        'var' => true,
        'void' => true,
        'while' => true,
    ];

    /** @var list<Token> */
    private array $tokens;
    private int $current = 0;

    public function __construct(
        private readonly string $source,
        private readonly string $sourceDescription = '<input>',
    )
    {
        try {
            $this->tokens = (new Lexer($source, $sourceDescription))->scan();
        } catch (ParseException $exception) {
            $message = DiagnosticFormatter::formatExceptionMessage($exception->getMessage(), $source, $sourceDescription, syntax: true);
            if ($message === $exception->getMessage()) {
                throw $exception;
            }

            throw new ParseException($message, 0, $exception);
        }
    }

    public function parse(): Expr
    {
        $expr = $this->parseConditional();
        $this->expect('eof', 'expected end of expression');

        return $expr;
    }

    private function parseConditional(): Expr
    {
        $condition = $this->parseBinary(1);
        if (!$this->match('?')) {
            return $condition;
        }

        $offset = $this->previous()->offset;
        $then = $this->parseConditional();
        $this->expect(':', 'expected ":" in conditional expression');
        $else = $this->parseConditional();

        return new Expr('conditional', null, [$condition, $then, $else], $offset);
    }

    private function parseBinary(int $minPrecedence): Expr
    {
        $left = $this->parseUnary();

        while (true) {
            $token = $this->peek();
            $operator = $this->operatorText($token);
            $precedence = $operator === null ? 0 : $this->precedence($operator);
            if ($precedence < $minPrecedence) {
                break;
            }

            $this->advance();
            $right = $this->parseBinary($precedence + 1);
            $left = new Expr('binary', $operator, [$left, $right], $token->offset);
        }

        return $left;
    }

    private function parseUnary(): Expr
    {
        if ($this->match('!') || $this->match('-')) {
            $token = $this->previous();
            return new Expr('unary', $token->text, [$this->parseUnary()], $token->offset);
        }

        return $this->parsePostfix($this->parsePrimary());
    }

    private function parsePostfix(Expr $expr): Expr
    {
        while (true) {
            if ($this->match('(')) {
                $open = $this->previous();
                $args = $this->parseArgumentList();
                if ($expr->kind !== 'ident') {
                    throw $this->error('only identifiers may be called directly', $open);
                }
                $expr = new Expr('call', $expr->value, $args, $expr->offset);
                continue;
            }

            if ($this->match('.')) {
                $dot = $this->previous();
                if ($this->match('?')) {
                    $name = $this->expectIdentifier('expected selector after ".?"', allowReserved: true);
                    $expr = new Expr('optional_select', $name->text, [], $dot->offset, $expr);
                    continue;
                }

                $name = $this->expectIdentifier('expected selector after "."', allowReserved: true);
                if ($this->match('(')) {
                    $args = $this->parseArgumentList();
                    $expr = new Expr('call', $name->text, $args, $dot->offset, $expr);
                } else {
                    $expr = new Expr('select', $name->text, [], $dot->offset, $expr);
                }
                continue;
            }

            if ($this->match('[')) {
                $open = $this->previous();
                $optional = $this->match('?');
                $index = $this->parseConditional();
                $this->expect(']', 'expected "]" after index expression');
                $expr = new Expr($optional ? 'optional_index' : 'index', null, [$index], $open->offset, $expr);
                continue;
            }

            if ($this->match('{')) {
                if ($expr->kind !== 'ident') {
                    throw $this->error('message literals require a type name', $this->previous());
                }
                $expr = $this->parseStructLiteral((string) $expr->value, $expr->offset);
                continue;
            }

            return $expr;
        }
    }

    private function parsePrimary(): Expr
    {
        if ($this->match('literal')) {
            $token = $this->previous();
            return Expr::literal($token->value, $token->offset);
        }

        if ($this->match('ident')) {
            $token = $this->previous();
            if (!$token->escapedIdentifier && isset(self::RESERVED[$token->text])) {
                throw $this->error(sprintf('reserved identifier "%s"', $token->text), $token);
            }

            $name = $token->text;
            while ($this->check('.') && $this->peek(1)->type === 'ident' && $this->peek(2)->type !== '(') {
                $this->advance();
                $selector = $this->advance();
                $name .= '.' . $selector->text;
            }

            return Expr::ident($name, $token->offset);
        }

        if ($this->match('.')) {
            $dot = $this->previous();
            $name = $this->expectIdentifier('expected identifier after "."')->text;
            while ($this->check('.') && $this->peek(1)->type === 'ident') {
                $this->advance();
                $name .= '.' . $this->advance()->text;
            }

            return Expr::ident('.' . $name, $dot->offset);
        }

        if ($this->match('(')) {
            $expr = $this->parseConditional();
            $this->expect(')', 'expected ")" after expression');
            return $expr;
        }

        if ($this->match('[')) {
            return $this->parseListLiteral($this->previous()->offset);
        }

        if ($this->match('{')) {
            return $this->parseMapLiteral($this->previous()->offset);
        }

        throw $this->error('expected expression', $this->peek());
    }

    /** @return list<Expr> */
    private function parseArgumentList(): array
    {
        $args = [];
        if ($this->match(')')) {
            return $args;
        }

        do {
            $args[] = $this->parseConditional();
        } while ($this->match(','));

        $this->expect(')', 'expected ")" after arguments');

        return $args;
    }

    private function parseListLiteral(int $offset): Expr
    {
        $values = [];
        if ($this->match(']')) {
            return new Expr('list', null, $values, $offset);
        }

        do {
            $optional = $this->match('?');
            $value = $this->parseConditional();
            $values[] = $optional ? new Expr('optional_element', null, [$value], $value->offset) : $value;
        } while ($this->match(',') && !$this->check(']'));

        $this->expect(']', 'expected "]" after list literal');

        return new Expr('list', null, $values, $offset);
    }

    private function parseMapLiteral(int $offset): Expr
    {
        $entries = [];
        if ($this->match('}')) {
            return new Expr('map', null, entries: $entries, offset: $offset);
        }

        do {
            $optional = $this->match('?');
            $key = $this->parseConditional();
            $this->expect(':', 'expected ":" after map key');
            $value = $this->parseConditional();
            $entries[] = ['key' => $key, 'value' => $value, 'optional' => $optional];
        } while ($this->match(',') && !$this->check('}'));

        $this->expect('}', 'expected "}" after map literal');

        return new Expr('map', null, entries: $entries, offset: $offset);
    }

    private function parseStructLiteral(string $typeName, int $offset): Expr
    {
        $fields = [];
        if ($this->match('}')) {
            return new Expr('struct', $typeName, entries: $fields, offset: $offset);
        }

        do {
            $optional = $this->match('?');
            $field = $this->expectIdentifier('expected field name in message literal', allowReserved: true);
            $this->expect(':', 'expected ":" after field name');
            $fields[] = ['field' => $field->text, 'value' => $this->parseConditional(), 'optional' => $optional];
        } while ($this->match(',') && !$this->check('}'));

        $this->expect('}', 'expected "}" after message literal');

        return new Expr('struct', $typeName, entries: $fields, offset: $offset);
    }

    private function precedence(string $operator): int
    {
        return match ($operator) {
            '||' => 1,
            '&&' => 2,
            '==', '!=', '<', '<=', '>', '>=', 'in' => 3,
            '+', '-' => 4,
            '*', '/', '%' => 5,
            default => 0,
        };
    }

    private function operatorText(Token $token): ?string
    {
        if ($token->type === 'op') {
            return $token->text;
        }

        return in_array($token->type, ['+', '-', '*', '/', '%', '<', '>'], true) ? $token->type : null;
    }

    private function match(string $type): bool
    {
        if (!$this->check($type)) {
            return false;
        }

        $this->advance();

        return true;
    }

    private function check(string $type): bool
    {
        return $this->peek()->type === $type;
    }

    private function expect(string $type, string $message): Token
    {
        if ($this->check($type)) {
            return $this->advance();
        }

        throw $this->error($message, $this->peek());
    }

    private function expectIdentifier(string $message, bool $allowReserved = false): Token
    {
        $token = $this->expect('ident', $message);
        if (!$allowReserved && !$token->escapedIdentifier && isset(self::RESERVED[$token->text])) {
            throw $this->error(sprintf('reserved identifier "%s"', $token->text), $token);
        }

        return $token;
    }

    private function advance(): Token
    {
        if (!$this->check('eof')) {
            $this->current++;
        }

        return $this->previous();
    }

    private function peek(int $ahead = 0): Token
    {
        return $this->tokens[$this->current + $ahead] ?? $this->tokens[array_key_last($this->tokens)];
    }

    private function previous(): Token
    {
        return $this->tokens[$this->current - 1];
    }

    private function error(string $message, Token $token): ParseException
    {
        return new ParseException(DiagnosticFormatter::format($message, $this->source, $token->offset, $this->sourceDescription, syntax: true));
    }
}
