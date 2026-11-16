<?php

declare(strict_types=1);

namespace G\Framework\Tests\Fixtures\App;

use Stringable;

/**
 * Stringable value: the HtmlConverter would accept it, the ShoutConverter of the project wins.
 */
final class Shout implements Stringable
{
    public function __construct(public readonly string $word)
    {
    }

    public function __toString(): string
    {
        return $this->word;
    }
}
