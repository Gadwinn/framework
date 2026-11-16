<?php

declare(strict_types=1);

namespace G\Framework\Tests\Fixtures\App;

final class InvokableController
{
    /**
     * @return array<string, string>
     */
    public function __invoke(string $slug): array
    {
        return ['slug' => $slug];
    }
}
