<?php

declare(strict_types=1);

namespace G\Framework\Tests\Fixtures\App;

use G\Http\Emitter\EmitterInterface;
use Psr\Http\Message\ResponseInterface;

final class RecordingEmitter implements EmitterInterface
{
    /** @var list<ResponseInterface> */
    public array $emitted = [];

    public function emit(ResponseInterface $response): void
    {
        $this->emitted[] = $response;
    }
}
