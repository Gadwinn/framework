<?php

declare(strict_types=1);

namespace G\Framework\Routing;

use G\Container\Container;
use G\Framework\Exception\FrameworkException;
use G\Router\Route\RouteInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Computes the arguments of a controller, for each parameter in this order of priority:
 *  1. typed with a class the request (or the route) is an instance of → the request (the route);
 *  2. named like a route parameter → its value, converted to the declared scalar type;
 *  3. typed with a class or an interface the container has → the container entry (an
 *     optional parameter only receives an entry defined in the container, never a class
 *     that would merely be autowired);
 *  4. the default value, else null when nullable, else a FrameworkException.
 *
 * Union and intersection types are ignored by the rules 1 to 3.
 */
final class ArgumentResolver
{
    private const string TYPE_STRING = 'string';
    private const string TYPE_MIXED = 'mixed';
    private const array SCALAR_TYPES = [self::TYPE_STRING, 'int', 'float', 'bool', self::TYPE_MIXED];

    public function __construct(private readonly ContainerInterface $container)
    {
    }

    /**
     * @return list<mixed>
     *
     * @throws InvalidRouteParameterException When a route parameter does not match the declared type.
     * @throws FrameworkException When a parameter cannot be resolved.
     */
    public function resolve(
        ReflectionFunctionAbstract $function,
        ServerRequestInterface $request,
        RouteInterface $route,
    ): array {
        $routeParams = $route->getParams();
        $arguments = [];

        foreach ($function->getParameters() as $param) {
            if ($param->isVariadic()) {
                break;
            }

            $type = $param->getType();
            $named = $type instanceof ReflectionNamedType ? $type : null;
            $typeName = $named?->getName();
            $className = $named !== null && !$named->isBuiltin() ? $named->getName() : null;

            if ($className !== null) {
                if ($request instanceof $className) {
                    $arguments[] = $request;
                    continue;
                }

                if ($route instanceof $className) {
                    $arguments[] = $route;
                    continue;
                }
            }

            $name = $param->getName();
            $scalar = $type === null || in_array($typeName, self::SCALAR_TYPES, true);
            if ($scalar && array_key_exists($name, $routeParams)) {
                $arguments[] = $this->convert($routeParams[$name], $typeName ?? self::TYPE_MIXED, $param, $function);
                continue;
            }

            // As in the container, an optional parameter keeps its default value rather than
            // receiving a class that was never defined and would only be autowired
            if (
                $className !== null
                && ($this->isDefined($className) || !$param->isDefaultValueAvailable())
                && $this->container->has($className)
            ) {
                $arguments[] = $this->container->get($className);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $arguments[] = $param->getDefaultValue();
                continue;
            }

            if ($type !== null && $param->allowsNull()) {
                $arguments[] = null;
                continue;
            }

            throw new FrameworkException(
                "Unable to resolve the parameter '\${$name}' of " . self::describe($function)
            );
        }

        return $arguments;
    }

    /**
     * Tell whether an identifier is explicitly defined in the container, as opposed to a
     * class it would only autowire. Without that information (other PSR-11 containers),
     * every entry the container has counts as defined.
     */
    private function isDefined(string $id): bool
    {
        return $this->container instanceof Container
            ? $this->container->isDefined($id)
            : $this->container->has($id);
    }

    /**
     * Human readable name of a controller.
     */
    public static function describe(ReflectionFunctionAbstract $function): string
    {
        if ($function instanceof ReflectionMethod) {
            return "'{$function->getDeclaringClass()->getName()}::{$function->getName()}()'";
        }

        return 'the Closure defined in ' . basename((string) $function->getFileName())
            . ':' . $function->getStartLine();
    }

    /**
     * @throws InvalidRouteParameterException
     */
    private function convert(
        mixed $value,
        string $type,
        ReflectionParameter $param,
        ReflectionFunctionAbstract $function,
    ): mixed {
        if (!is_string($value) || $type === self::TYPE_STRING || $type === self::TYPE_MIXED) {
            return $value;
        }

        $converted = match ($type) {
            'int' => filter_var($value, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
            'float' => filter_var($value, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE),
            default => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),
        };

        if ($converted === null) {
            throw new InvalidRouteParameterException(
                "The route parameter '{$param->getName()}' of " . self::describe($function) . " is not a valid $type"
            );
        }

        return $converted;
    }
}
