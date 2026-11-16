<?php

declare(strict_types=1);

namespace G\Framework\Tests\Fixtures\App;

use G\Router\Route\RouteInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Controller without parent class, its dependencies injected by the constructor.
 */
final class HomeController
{
    public function __construct(private readonly Greeter $greeter)
    {
    }

    public function index(): string
    {
        return '<h1>Home</h1>';
    }

    public function hello(string $name): string
    {
        return $this->greeter->greet($name);
    }

    /**
     * @return array<string, mixed>
     */
    public function user(int $id, ServerRequestInterface $request, RouteInterface $route): array
    {
        return ['id' => $id, 'path' => $request->getUri()->getPath(), 'route' => $route->getOption('name')];
    }

    public function nothing(): void
    {
    }

    public function shout(string $word): Shout
    {
        return new Shout($word);
    }

    public function response(ResponseFactoryInterface $factory): ResponseInterface
    {
        return $factory->createResponse(201);
    }

    public function object(): object
    {
        return new \stdClass();
    }
}
