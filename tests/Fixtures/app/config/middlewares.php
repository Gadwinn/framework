<?php

declare(strict_types=1);

use G\Framework\Tests\Fixtures\App\HeaderMiddleware;
use G\Router\Middleware\RouterMiddleware;

return [
    HeaderMiddleware::class,
    RouterMiddleware::class,
];
