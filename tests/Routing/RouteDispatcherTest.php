<?php

declare(strict_types=1);

namespace G\Framework\Tests\Routing;

use G\Container\Container;
use G\Framework\Exception\FrameworkException;
use G\Framework\Response\Converter\HtmlConverter;
use G\Framework\Response\ResponseConverter;
use G\Framework\Routing\ArgumentResolver;
use G\Framework\Routing\RouteDispatcher;
use G\Framework\Tests\Fixtures\App\HomeController;
use G\Http\Message\ResponseFactory;
use G\Http\Message\ServerRequestFactory;
use G\Http\Message\StreamFactory;
use G\Router\Middleware\RouterMiddleware;
use G\Router\Route\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class RouteDispatcherTest extends TestCase
{
    private function dispatch(mixed $target, mixed $route = null): ResponseInterface
    {
        $container = new Container();
        $converter = new ResponseConverter([new HtmlConverter(new ResponseFactory(), new StreamFactory())]);
        $dispatcher = new RouteDispatcher($container, new ArgumentResolver($container), $converter, new ResponseFactory());

        /** @var callable|array<mixed>|string $target */
        $route ??= new Route('GET', '/path', $target, ['name' => 'the-route']);

        return $dispatcher->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/path')
                ->withAttribute(RouterMiddleware::ROUTE_ATTRIBUTE_NAME, $route)
        );
    }

    public function testStringTargetWithMethod(): void
    {
        $this->assertSame('<h1>Home</h1>', (string) $this->dispatch(HomeController::class . '::index')->getBody());
    }

    public function testArrayTargetWithAnObject(): void
    {
        $controller = new class {
            public function show(): string
            {
                return 'object';
            }
        };

        $this->assertSame('object', (string) $this->dispatch([$controller, 'show'])->getBody());
    }

    public function testMissingRouteAttribute(): void
    {
        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage('RouterMiddleware is missing');

        $this->dispatch(null, 'not a route');
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function invalidTargets(): array
    {
        $static = new class {
            public static function create(): string
            {
                return '';
            }
        };

        $private = new class {
            private function secret(): string
            {
                return '';
            }
        };

        return [
            'private method' => [[$private, 'secret'], 'is not a public instance method'],
            'unknown class' => ['App\\Unknown', "Unknown controller class 'App\\Unknown' for the route 'the-route'"],
            'unknown class with method' => [['App\\Unknown', 'index'], 'Unknown controller class'],
            'unknown method' => [[HomeController::class, 'missing'], 'HomeController::missing() not found'],
            'constructor' => [[HomeController::class, '__construct'], 'is not a public instance method'],
            'static method' => [[$static, 'create'], 'is not a public instance method'],
            'not invokable' => [HomeController::class, 'HomeController::__invoke() not found'],
            'function name' => ['strlen', "Unknown controller class 'strlen'"],
            'invalid array' => [['a', 'b', 'c'], "Invalid target for the route 'the-route'"],
        ];
    }

    #[DataProvider('invalidTargets')]
    public function testInvalidTarget(mixed $target, string $message): void
    {
        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage($message);

        $this->dispatch($target);
    }

    public function testUnsupportedResultNamesTypeControllerAndRoute(): void
    {
        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage(
            "No response converter supports the array returned by '" . HomeController::class . "::user()' (route 'the-route'"
        );

        $route = (new Route('GET', '/path', [HomeController::class, 'user'], ['name' => 'the-route']))
            ->setParams(['id' => '1']);
        $this->dispatch(null, $route);
    }
}
