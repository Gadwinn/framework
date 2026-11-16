<?php

declare(strict_types=1);

use G\Framework\Tests\Fixtures\App\HandlerController;
use G\Framework\Tests\Fixtures\App\HomeController;
use G\Framework\Tests\Fixtures\App\InvokableController;
use G\Router\RouterInterface;

return static function (RouterInterface $router): void {
    $router->get('/', [HomeController::class, 'index'], ['name' => 'home']);
    $router->get('/hello/{name}', HomeController::class . '::hello', ['name' => 'hello']);
    $router->get('/users/{id}', [HomeController::class, 'user'], ['name' => 'user']);
    $router->get('/nothing', [HomeController::class, 'nothing']);
    $router->get('/shout/{word}', [HomeController::class, 'shout']);
    $router->post('/created', [HomeController::class, 'response']);
    $router->get('/object', [HomeController::class, 'object']);
    $router->get('/posts/{slug}', InvokableController::class);
    $router->any('/handler', HandlerController::class);
    $router->get('/closure/{n}', static fn (float $n): array => ['double' => $n * 2]);
};
