<?php

declare(strict_types=1);

namespace G\Framework;

use Closure;
use G\Container\Container;
use G\Framework\Exception\FrameworkException;
use G\Framework\Response\Converter\HtmlConverter;
use G\Framework\Response\Converter\JsonConverter;
use G\Framework\Response\Converter\NoContentConverter;
use G\Framework\Response\Converter\PsrResponseConverter;
use G\Framework\Response\ResponseConverter;
use G\Framework\Response\ResponseConverterInterface;
use G\Framework\Routing\RouterErrorHandler;
use G\Http\Emitter\Emitter;
use G\Http\Emitter\EmitterInterface;
use G\Http\Message\RequestFactory;
use G\Http\Message\ResponseFactory;
use G\Http\Message\ServerRequestFactory;
use G\Http\Message\StreamFactory;
use G\Http\Message\UploadedFileFactory;
use G\Http\Message\UriFactory;
use G\Router\Middleware\RouterMiddleware;
use G\Router\Router;
use G\Router\RouterInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Builds an Application from the configuration files of a project:
 *  - config/services.php     array: container id => definition (see gadwinn/psr-container);
 *  - config/middlewares.php  list: middlewares in order of execution (class names or instances);
 *  - config/routes.php       Closure(RouterInterface): void declaring the routes;
 *  - config/converters.php   optional list of response converter class names, tried before
 *                            the converters of the framework.
 *
 * The services the framework needs are registered unless the project defines them.
 */
final class ApplicationFactory
{
    public const string SERVICES_FILE = 'config/services.php';
    public const string MIDDLEWARES_FILE = 'config/middlewares.php';
    public const string ROUTES_FILE = 'config/routes.php';
    public const string CONVERTERS_FILE = 'config/converters.php';
    public const string RESET_FILE = 'config/reset.php';

    /** Converters of the framework, tried after those of the project */
    private const array CONVERTERS = [
        PsrResponseConverter::class,
        HtmlConverter::class,
        JsonConverter::class,
        NoContentConverter::class,
    ];

    /**
     * @throws FrameworkException When a configuration file is missing or invalid.
     */
    public static function fromDirectory(string $projectDir): Application
    {
        $projectDir = rtrim($projectDir, '/\\');

        $services = self::load($projectDir, self::SERVICES_FILE);
        if (!is_array($services) || array_is_list($services) && $services !== []) {
            throw self::invalid(self::SERVICES_FILE, 'an array of container id => definition');
        }

        $middlewares = self::load($projectDir, self::MIDDLEWARES_FILE);
        $middlewares = self::listOf(
            $middlewares,
            static fn (mixed $m): string|MiddlewareInterface|null
                => is_string($m) || $m instanceof MiddlewareInterface ? $m : null,
        ) ?? throw self::invalid(self::MIDDLEWARES_FILE, 'a list of middleware class names or instances');

        $routes = self::load($projectDir, self::ROUTES_FILE);
        if (!$routes instanceof Closure) {
            throw self::invalid(self::ROUTES_FILE, 'a Closure(RouterInterface): void');
        }

        $converters = is_file($projectDir . '/' . self::CONVERTERS_FILE)
            ? self::load($projectDir, self::CONVERTERS_FILE)
            : [];
        $converters = self::listOf($converters, static fn (mixed $c): ?string => is_string($c) ? $c : null)
            ?? throw self::invalid(self::CONVERTERS_FILE, 'a list of response converter class names');

        $resetters = is_file($projectDir . '/' . self::RESET_FILE)
            ? self::load($projectDir, self::RESET_FILE)
            : [];
        if (
            !is_array($resetters)
            || array_is_list($resetters) && $resetters !== []
            || array_filter($resetters, static fn (mixed $r): bool => !$r instanceof Closure) !== []
        ) {
            throw self::invalid(self::RESET_FILE, 'an array of container id => Closure(object): void');
        }

        $container = new Container();
        foreach ($services as $id => $definition) {
            $container->set((string) $id, $definition);
        }
        self::registerDefaults($container, $converters);

        $router = $container->get(RouterInterface::class);
        if (!$router instanceof RouterInterface) {
            throw new FrameworkException("The container entry '" . RouterInterface::class . "' is not a router");
        }
        $routes($router);

        /** @var array<string, Closure(object): void> $resetters */
        return new Application($container, $middlewares, $resetters);
    }

    /**
     * @param list<string> $converters Converter class names of the project
     */
    private static function registerDefaults(Container $container, array $converters): void
    {
        $defaults = [
            ResponseFactoryInterface::class => ResponseFactory::class,
            RequestFactoryInterface::class => RequestFactory::class,
            ServerRequestFactoryInterface::class => ServerRequestFactory::class,
            StreamFactoryInterface::class => StreamFactory::class,
            UploadedFileFactoryInterface::class => UploadedFileFactory::class,
            UriFactoryInterface::class => UriFactory::class,
            RouterInterface::class => Router::class,
            RouterMiddleware::class => static fn (RouterInterface $router, RouterErrorHandler $onError)
                => new RouterMiddleware($router, $onError),
            EmitterInterface::class => Emitter::class,
            ResponseConverterInterface::class => static fn (ContainerInterface $c): ResponseConverter
                => new ResponseConverter(array_map(
                    static fn (string $class): ResponseConverterInterface => self::converter($c, $class),
                    [...$converters, ...self::CONVERTERS]
                )),
        ];

        foreach ($defaults as $id => $definition) {
            if (!$container->isDefined($id)) {
                $container->set($id, $definition);
            }
        }
    }

    /**
     * @throws FrameworkException
     */
    private static function converter(ContainerInterface $container, string $class): ResponseConverterInterface
    {
        $converter = $container->has($class) ? $container->get($class) : null;
        if (!$converter instanceof ResponseConverterInterface) {
            throw new FrameworkException(
                "The response converter '$class' (" . self::CONVERTERS_FILE . ') must be a class implementing '
                    . ResponseConverterInterface::class
            );
        }

        return $converter;
    }

    /**
     * @throws FrameworkException When the file does not exist.
     */
    private static function load(string $projectDir, string $file): mixed
    {
        $path = $projectDir . '/' . $file;
        if (!is_file($path)) {
            throw new FrameworkException("Configuration file '$file' not found in '$projectDir'");
        }

        return (static fn (string $path): mixed => require $path)($path);
    }

    /**
     * Checks that a value is a list and maps each item, null meaning an invalid item.
     *
     * @template T
     * @param Closure(mixed): (T|null) $map
     *
     * @return list<T>|null Null when the value is not a list or an item is invalid.
     */
    private static function listOf(mixed $value, Closure $map): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $list = [];
        foreach ($value as $item) {
            $mapped = $map($item);
            if ($mapped === null) {
                return null;
            }
            $list[] = $mapped;
        }

        return $list;
    }
    private static function invalid(string $file, string $expected): FrameworkException
    {
        return new FrameworkException("Configuration file '$file' must return $expected");
    }
}
