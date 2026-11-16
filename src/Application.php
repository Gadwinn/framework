<?php

declare(strict_types=1);

namespace G\Framework;

use G\Framework\Exception\FrameworkException;
use G\Framework\Routing\RouteDispatcher;
use G\Http\Emitter\EmitterInterface;
use G\Http\Message\ServerRequestFactory;
use G\Http\Server\RequestHandler;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The application: an ordered stack of PSR-15 middlewares ending with the RouteDispatcher.
 *
 * handle() keeps no state between two requests, so one instance can serve any number of
 * requests (FrankenPHP worker mode and other long-running servers).
 */
final class Application implements RequestHandlerInterface
{
    private readonly RequestHandler $pipeline;

    /**
     * @param ContainerInterface $container
     * @param list<string|MiddlewareInterface> $middlewares In order of execution: container ids
     *                                                      (class names) or instances.
     *
     * @throws FrameworkException When a middleware cannot be obtained from the container.
     */
    public function __construct(
        private readonly ContainerInterface $container,
        array $middlewares,
    ) {
        $dispatcher = $container->get(RouteDispatcher::class);
        if (!$dispatcher instanceof RequestHandlerInterface) {
            throw new FrameworkException(
                "The container entry '" . RouteDispatcher::class . "' is not a RequestHandlerInterface"
            );
        }

        $this->pipeline = new RequestHandler($dispatcher);
        foreach ($middlewares as $middleware) {
            $this->pipeline->addMiddlewares($this->middleware($middleware));
        }
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * Runs the request through the middleware stack.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->pipeline->handle($request);
    }

    /**
     * Handles the request built from the PHP globals (or the given one) and emits the response.
     *
     * @throws FrameworkException When the container has no EmitterInterface.
     */
    public function run(?ServerRequestInterface $request = null): void
    {
        $emitter = $this->container->get(EmitterInterface::class);
        if (!$emitter instanceof EmitterInterface) {
            throw new FrameworkException(
                "The container entry '" . EmitterInterface::class . "' is not an EmitterInterface"
            );
        }

        $emitter->emit($this->handle($request ?? ServerRequestFactory::createServerRequestFromGlobals()));
    }

    /**
     * @throws FrameworkException
     */
    private function middleware(string|MiddlewareInterface $middleware): MiddlewareInterface
    {
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }

        if (!$this->container->has($middleware)) {
            throw new FrameworkException(
                "Unknown middleware '$middleware': declare it in config/services.php"
            );
        }

        $instance = $this->container->get($middleware);
        if (!$instance instanceof MiddlewareInterface) {
            throw new FrameworkException("The middleware '$middleware' does not implement MiddlewareInterface");
        }

        return $instance;
    }
}
