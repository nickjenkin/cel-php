<?php

declare(strict_types=1);

namespace CEL\Benchmarks;

use CEL\Ast;
use CEL\CheckedAst;
use CEL\Environment;
use CEL\Program;
use CEL\Type;
use CEL\UnknownValue;
use PhpBench\Attributes\BeforeMethods;
use PhpBench\Attributes\Groups;
use PhpBench\Attributes\Iterations;
use PhpBench\Attributes\OutputTimeUnit;
use PhpBench\Attributes\Revs;
use PhpBench\Attributes\Subject;
use PhpBench\Attributes\Warmup;

#[Groups(['core'])]
#[BeforeMethods('setUp')]
#[Iterations(5)]
#[Revs(50)]
#[Warmup(1)]
#[OutputTimeUnit('microseconds')]
final class CoreCelBench
{
    private Environment $standardEnv;

    private Environment $typedEnv;

    private Ast $typedAst;

    private CheckedAst $roundTripAst;

    private Program $arithmeticProgram;

    private Program $booleanProgram;

    private Program $membershipProgram;

    private Program $stringsProgram;

    private Program $macrosProgram;

    private Program $optionalsProgram;

    private Program $extensionsProgram;

    private Program $partialProgram;

    /** @var array<string, mixed> */
    private array $booleanActivation;

    /** @var array<string, mixed> */
    private array $membershipActivation;

    /** @var array<string, mixed> */
    private array $stringsActivation;

    /** @var array<string, mixed> */
    private array $macrosActivation;

    /** @var array<string, mixed> */
    private array $partialActivation;

    public function setUp(): void
    {
        $this->standardEnv = Environment::standard();
        $this->typedEnv = Environment::builder()
            ->variable('path', Type::string())
            ->variable('roles', Type::list(Type::string()))
            ->variable('attempts', Type::int())
            ->variable('attributes', Type::map(Type::string(), Type::string()))
            ->variable('users', Type::list(Type::dyn()))
            ->variable('request', Type::dyn())
            ->variable('limit', Type::int())
            ->variable('tags', Type::list(Type::string()))
            ->build();

        $this->typedAst = $this->typedEnv->parse('path.startsWith("/admin") && size(roles) > 0 && attempts < 3');
        $this->roundTripAst = $this->typedEnv->compile('roles.exists(role, role == "admin") && attributes["region"] == "ap-southeast-2"');

        $this->arithmeticProgram = $this->standardEnv->program(
            $this->standardEnv->compile('(1 + 2 * 3 - 4 / 2) == 5 && type(uint(7)) == uint'),
        );
        $this->booleanProgram = $this->typedEnv->program(
            $this->typedEnv->compile('path.startsWith("/admin") && attempts < 3 || "break-glass" in roles'),
        );
        $this->membershipProgram = $this->typedEnv->program(
            $this->typedEnv->compile('"editor" in roles && attributes["region"] == "ap-southeast-2" && size(roles) >= 2'),
        );
        $this->stringsProgram = $this->typedEnv->program(
            $this->typedEnv->compile('path.startsWith("/admin") && path.contains("settings") && path.matches("^/admin/.+")'),
        );
        $this->macrosProgram = $this->typedEnv->program(
            $this->typedEnv->compile('users.filter(u, u.active).map(u, u.email).exists(e, e.endsWith("@example.test"))'),
        );
        $this->optionalsProgram = $this->standardEnv->program(
            $this->standardEnv->compile('optional.of("ready").hasValue() && optional.none().orValue("fallback") == "fallback"'),
        );
        $this->extensionsProgram = $this->standardEnv->program(
            $this->standardEnv->compile('base64.decode(base64.encode(b"hello")) == b"hello" && math.ceil(1.2) == 2 && "desserts".reverse() == "stressed" && cidr("192.168.0.0/24").containsIP("192.168.0.1")'),
        );
        $this->partialProgram = $this->typedEnv->program(
            $this->typedEnv->compile('request.score > limit && "trusted" in tags'),
        );

        $this->booleanActivation = [
            'path' => '/admin/settings',
            'roles' => ['viewer'],
            'attempts' => 1,
        ];
        $this->membershipActivation = [
            'roles' => ['viewer', 'editor'],
            'attributes' => ['region' => 'ap-southeast-2'],
        ];
        $this->stringsActivation = ['path' => '/admin/settings/security'];
        $this->macrosActivation = [
            'users' => [
                ['active' => true, 'email' => 'alice@example.test'],
                ['active' => false, 'email' => 'bob@example.test'],
                ['active' => true, 'email' => 'carol@example.org'],
            ],
        ];
        $this->partialActivation = [
            'request' => UnknownValue::attribute('request'),
            'limit' => 75,
            'tags' => ['trusted', 'internal'],
        ];
    }

    #[Subject]
    public function benchEnvironmentConstruction(): Environment
    {
        return Environment::builder()
            ->variable('path', Type::string())
            ->variable('roles', Type::list(Type::string()))
            ->variable('attributes', Type::map(Type::string(), Type::string()))
            ->build();
    }

    #[Subject]
    public function benchParseSimple(): Ast
    {
        return $this->standardEnv->parse('1 + 2 * 3 == 7');
    }

    #[Subject]
    public function benchParseComplex(): Ast
    {
        return $this->standardEnv->parse('orders.exists(o, o.total_cents > limit && o.labels["region"] in regions) ? "review" : "allow"');
    }

    #[Subject]
    public function benchCheckTypedExpression(): CheckedAst
    {
        return $this->typedEnv->check($this->typedAst);
    }

    #[Subject]
    public function benchCompileExpression(): CheckedAst
    {
        return $this->typedEnv->compile('path.startsWith("/admin") && size(roles) > 0 && attempts < 3');
    }

    #[Subject]
    public function benchEvalArithmetic(): bool
    {
        return $this->arithmeticProgram->eval();
    }

    #[Subject]
    public function benchEvalBooleanLogic(): bool
    {
        return $this->booleanProgram->eval($this->booleanActivation);
    }

    #[Subject]
    public function benchEvalListsAndMaps(): bool
    {
        return $this->membershipProgram->eval($this->membershipActivation);
    }

    #[Subject]
    public function benchEvalStringOperations(): bool
    {
        return $this->stringsProgram->eval($this->stringsActivation);
    }

    #[Subject]
    public function benchEvalMacros(): bool
    {
        return $this->macrosProgram->eval($this->macrosActivation);
    }

    #[Subject]
    public function benchEvalOptionals(): bool
    {
        return $this->optionalsProgram->eval();
    }

    #[Subject]
    public function benchEvalExtensions(): bool
    {
        return $this->extensionsProgram->eval();
    }

    #[Subject]
    public function benchPartialEvaluation(): string
    {
        return $this->partialProgram
            ->evalPartial($this->partialActivation)
            ->residualExpression();
    }

    #[Subject]
    public function benchParsedExprRoundTrip(): Ast
    {
        return Ast::fromParsedExpr($this->roundTripAst->toParsedExpr());
    }

    #[Subject]
    public function benchCheckedExprRoundTrip(): Ast
    {
        return Ast::fromCheckedExpr($this->roundTripAst->toCheckedExpr());
    }
}
