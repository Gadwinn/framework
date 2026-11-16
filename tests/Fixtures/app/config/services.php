<?php

declare(strict_types=1);

use G\Framework\Tests\Fixtures\App\Greeter;
use G\Framework\Tests\Fixtures\App\RecordingEmitter;
use G\Http\Emitter\EmitterInterface;

return [
    // A service the autowiring cannot guess (scalar argument)
    Greeter::class => static fn (): Greeter => new Greeter('Bonjour'),
    // A default service of the framework replaced by the project
    EmitterInterface::class => RecordingEmitter::class,
];
