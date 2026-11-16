<?php

declare(strict_types=1);

namespace G\Framework\Routing;

use Closure;
use G\Framework\Exception\FrameworkException;
use G\Framework\Response\ResponseConverterInterface;
use G\Router\Middleware\RouterMiddleware;
use G\Router\Route\RouteInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/**
 * Last link of the middleware stack: calls the target of the route matched by the
 * RouterMiddleware and converts its result into a response.
 *
 * Accepted targets: [Controller::class, 'method'], 'Controller::method', Controller::class
 * (an invokable class, or a RequestHandlerInterface) and Closure. A controller is always
 * obtained from the container.
 */
final class RouteDispatcher implements RequestHandlerInterface
{
    public const int STATUS_NOT_FOUND = 404;
    private const string INVOKE = '__invoke';
    private const int CALLABLE_ARRAY_SIZE = 2;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ArgumentResolver $argumentResolver,
        private readonly ResponseConverterInterface $converter,
        private readonly ResponseFactoryInterface $responseFactory,
    ) {
    }

    /**
     * @throws FrameworkException On a missing route attribute, an invalid target, an
     *                            unresolvable argument or a result no converter supports.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $route = $request->getAttribute(RouterMiddleware::ROUTE_ATTRIBUTE_NAME);
        if (!$route instanceof RouteInterface) {
            throw new FrameworkException(
                "No route attribute on the request: the RouterMiddleware is missing from the middleware stack"
            );
        }

        [$controller, $function] = $this->resolveTarget($route);

        if ($function === null) {
            // A RequestHandlerInterface controller handles the request itself
            assert($controller instanceof RequestHandlerInterface);

            return $controller->handle($request);
        }

        try {
            $arguments = $this->argumentResolver->resolve($function, $request, $route);
        } catch (InvalidRouteParameterException) {
            return $this->responseFactory->createResponse(self::STATUS_NOT_FOUND);
        }

        $result = $function instanceof ReflectionMethod
            ? $function->invokeArgs($controller, $arguments)
            : ($function instanceof ReflectionFunction ? $function->invokeArgs($arguments) : null);

        if (!$this->converter->supports($result)) {
            throw new FrameworkException(
                'No response converter supports the ' . get_debug_type($result) . ' returned by '
                    . ArgumentResolver::describe($function) . ' (route ' . $this->describeRoute($route) . ')'
            );
        }

        return $this->converter->convert($result, $request);
    }

    /**
     * @return array{object, ?ReflectionFunctionAbstract} The controller and the function to call
     *                                                    (null for a RequestHandlerInterface).
     *
     * @throws FrameworkException
     */
    private function resolveTarget(RouteInterface $route): array
    {
        $target = $route->getTarget();

        if ($target instanceof Closure) {
            return [$target, new ReflectionFunction($target)];
        }

        if (is_string($target) && str_contains($target, '::')) {
            $target = explode('::', $target, 2);
        }

        if (is_string($target)) {
            $controller = $this->controller($target, $route);
            if ($controller instanceof RequestHandlerInterface) {
                return [$controller, null];
            }

            return [$controller, $this->method($controller, self::INVOKE, $route)];
        }

        if (
            is_array($target) && array_is_list($target) && count($target) === self::CALLABLE_ARRAY_SIZE
            && (is_string($target[0]) || is_object($target[0])) && is_string($target[1])
        ) {
            $controller = is_object($target[0]) ? $target[0] : $this->controller($target[0], $route);

            return [$controller, $this->method($controller, $target[1], $route)];
        }

        throw new FrameworkException('Invalid target for the route ' . $this->describeRoute($route));
    }

    /**
     * @throws FrameworkException
     */
    private function controller(string $class, RouteInterface $route): object
    {
        if (!class_exists($class)) {
            throw new FrameworkException(
                "Unknown controller class '$class' for the route " . $this->describeRoute($route)
            );
        }

        $controller = $this->container->get($class);
        if (!is_object($controller)) {
            throw new FrameworkException(
                "The container entry '$class' is not an object (route " . $this->describeRoute($route) . ')'
            );
        }

        return $controller;
    }

    /**
     * @throws FrameworkException
     */
    private function method(object $controller, string $method, RouteInterface $route): ReflectionMethod
    {
        if (!method_exists($controller, $method)) {
            throw new FrameworkException(
                'Method ' . $controller::class . "::$method() not found for the route " . $this->describeRoute($route)
            );
        }

        $reflection = new ReflectionMethod($controller, $method);
        $magic = str_starts_with($method, '__') && strtolower($method) !== self::INVOKE;
        if (!$reflection->isPublic() || $reflection->isStatic() || $reflection->isAbstract() || $magic) {
            throw new FrameworkException(
                'Method ' . $controller::class . "::$method() is not a public instance method (route "
                    . $this->describeRoute($route) . ')'
            );
        }

        return $reflection;
    }

    private function describeRoute(RouteInterface $route): string
    {
        $name = $route->getOption('name');
        $description = strtoupper($route->getMethod()) . ' ' . $route->getPath();

        return is_string($name) && $name !== '' ? "'$name' ($description)" : "'$description'";
    }
}
