<?php

declare(strict_types=1);

namespace G\Framework\Tests\Fixtures\App;

final class Greeter
{
    public function __construct(private readonly string $greeting = 'Hello')
    {
    }

    public function greet(string $name): string
    {
        return "{$this->greeting} {$name}";
    }
}
