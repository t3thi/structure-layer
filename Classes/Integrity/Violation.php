<?php

declare(strict_types=1);

namespace T3thi\StructureLayer\Integrity;

final readonly class Violation implements \Stringable
{
    public function __construct(
        public string $rule,
        public string $message,
        public bool $isNotice = false,
    ) {}

    public function __toString(): string
    {
        return '[' . $this->rule . ($this->isNotice ? ', notice' : '') . '] ' . $this->message;
    }
}
