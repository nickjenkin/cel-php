<?php

declare(strict_types=1);

namespace CEL\Proto;

use Google\Protobuf\Internal\Message;

final readonly class ProtoWrapperValue
{
    /** @param class-string<Message> $className */
    public function __construct(
        private string $className,
        private mixed $wrapped,
    ) {
    }

    /** @return class-string<Message> */
    public function className(): string
    {
        return $this->className;
    }

    public function wrapped(): mixed
    {
        return $this->wrapped;
    }
}
