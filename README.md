# gadwinn/framework

A lightweight PSR-7 / PSR-15 framework assembling the `gadwinn/*` packages: a container with
autowiring, a router, a middleware stack and a response emitter. Nothing else is mandatory:
every other feature (error pages, CSRF, proxies, logging, database...) is an optional package
you enable in the configuration of your project.

- Controllers are plain classes, without parent class; their dependencies come through the constructor.
- Configuration in PHP, `public/` as the only web root.
- No global or static state: one `Application` serves any number of requests (FrankenPHP worker mode).

Requires PHP 8.3+.

## Getting started

The quickest way is the project template:

```bash
composer create-project gadwinn/skeleton my-app
```

Or by hand, in an existing project:

```bash
composer require gadwinn/framework
```

`public/index.php`:

```php
<?php

declare(strict_types=1);

use G\Framework\ApplicationFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

ApplicationFactory::fromDirectory(dirname(__DIR__))->run();
```

`ApplicationFactory::fromDirectory()` reads the configuration files of the project and returns
an `Application`. `run()` builds the request from the PHP globals, runs it through the
middleware stack and emits the response. `handle($request)` does the same without the globals
and the emission (PSR-15 `RequestHandlerInterface`).

## Configuration files

| File | Returns | Required |
|---|---|---|
| `config/services.php` | `array` of container id => definition | yes |
| `config/middlewares.php` | `list` of middlewares, in order of execution | yes |
| `config/routes.php` | `Closure(RouterInterface): void` | yes |
| `config/converters.php` | `list` of response converter class names | no |

A missing required file, or a file returning the wrong type, throws a
`G\Framework\Exception\FrameworkException` naming the file.

### `config/services.php`

The container ([`gadwinn/psr-container`](https://github.com/Gadwinn/psr-container)) builds any
instantiable class on its own (autowiring). Declare only what it cannot guess:

```php
<?php

use Psr\Container\ContainerInterface;

return [
    // An interface: which implementation to use
    Psr\Log\LoggerInterface::class => G\Log\Logger::class,

    // An object that needs configuration values
    PDO::class => static fn (): PDO => new PDO($_ENV['DB_DSN'], $_ENV['DB_USER'], $_ENV['DB_PASSWORD']),

    // A service built from other services: a factory receives the container,
    // or any service it asks for by type
    App\Mailer::class => static fn (ContainerInterface $c): App\Mailer
        => new App\Mailer($c->get(Psr\Log\LoggerInterface::class), $_ENV['MAIL_FROM']),
];
```

The framework registers the services it needs **unless the project defines them**:

| Id | Default |
|---|---|
| PSR-17 factories (`ResponseFactoryInterface`, `StreamFactoryInterface`, `ServerRequestFactoryInterface`, `RequestFactoryInterface`, `UploadedFileFactoryInterface`, `UriFactoryInterface`) | classes of `gadwinn/psr-http-message` |
| `G\Router\RouterInterface` | a shared `G\Router\Router` |
| `G\Router\Middleware\RouterMiddleware` | built with that router and `G\Framework\Routing\RouterErrorHandler` |
| `G\Http\Emitter\EmitterInterface` | `G\Http\Emitter\Emitter` |
| `G\Framework\Response\ResponseConverterInterface` | the chain of converters (see below) |

For instance, to answer 404 and 405 with your own pages, declare your own `RouterMiddleware`:

```php
G\Router\Middleware\RouterMiddleware::class => static fn (G\Router\RouterInterface $router)
    => new G\Router\Middleware\RouterMiddleware($router, new App\Error\RouterErrorPage()),
```

### `config/middlewares.php`

The middlewares run in the order of the list: class names (obtained from the container) or
instances. Only the `RouterMiddleware` is required; the controller of the matched route is
called after the last middleware.

```php
<?php

return [
    G\TrustedProxyMiddleware\TrustedProxyMiddleware::class,   // behind a reverse proxy (declared in services.php)
    G\ErrorCatcherMiddleware\ErrorCatcherMiddleware::class,   // declared in services.php, with its error handler
    G\HttpMethodMiddleware\MethodOverrideMiddleware::class,
    G\HttpMethodMiddleware\BodyParserMiddleware::class,
    G\Router\Middleware\RouterMiddleware::class,
];
```

### `config/routes.php`

```php
<?php

use App\Controller\HomeController;
use G\Router\RouterInterface;

return static function (RouterInterface $router): void {
    // $router->setBasePath('/my-app/public');   // application served in a sub-folder
    $router->get('/', [HomeController::class, 'index'], ['name' => 'home']);
    $router->get('/hello/{name}', [HomeController::class, 'hello'], ['name' => 'hello']);
};
```

Routes can also be declared on the controllers with the `#[Group]` (class) and `#[Route]`
(method) attributes of the router, and loaded in one line:

```php
use G\Router\Attribute\AttributeLoader;

return static function (RouterInterface $router): void {
    (new AttributeLoader($router))->loadDirectory(dirname(__DIR__) . '/src/Controller', 'App\Controller');
};
```

```php
#[Group('/users', name: 'users.')]
final class UserController
{
    #[Route('/{id:i}', name: 'show')]   // GET /users/{id}, named users.show
    public function show(int $id): array { /* ... */ }
}
```

See [`gadwinn/router`](https://github.com/Gadwinn/router) for patterns, groups, attributes and reverse URLs.

## Controllers

A route target can be:

| Target | Called |
|---|---|
| `[UserController::class, 'show']` or `'UserController::show'` | the public method `show()` |
| `UserController::class` | `__invoke()`, or `handle($request)` for a `RequestHandlerInterface` |
| a `Closure` | the closure |

A controller is always obtained from the container, so its constructor dependencies are
injected. An invalid target throws a `FrameworkException` naming the route.

```php
namespace App\Controller;

use App\Repository\UserRepository;
use Psr\Http\Message\ServerRequestInterface;

final class UserController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    public function show(int $id, ServerRequestInterface $request): array
    {
        return $this->users->find($id)->toArray();
    }
}
```

The arguments of the method are resolved, for each parameter, in this order:

1. typed `ServerRequestInterface` → the request; typed `G\Router\Route\RouteInterface` → the matched route;
2. named like a route parameter → its value, converted to the declared type (`int`, `float`,
   `bool`, `string`); a value that does not convert (`/users/abc` for an `int`) gives a 404;
3. typed with a class or an interface → the service from the container (an optional
   parameter such as `?Foo $foo = null` only receives a service defined in
   `config/services.php`, it is never autowired);
4. the default value, else `null` when nullable, else a `FrameworkException` naming the
   parameter and the controller.

Union and intersection types are ignored by the rules 1 to 3.

### What a controller returns

| Result | Response |
|---|---|
| `ResponseInterface` | returned as is |
| `string`, `Stringable` | 200, `text/html; charset=utf-8` |
| `array`, `JsonSerializable` | 200, `application/json` |
| `null` (or `void`) | 204, empty |

Anything else throws a `FrameworkException` naming the type and the controller.

### Custom converters

Implement `G\Framework\Response\ResponseConverterInterface` and list the class in
`config/converters.php`. The converters of the project are tried **before** those of the
framework, in the order of the list:

```php
final class ViewConverter implements ResponseConverterInterface
{
    public function __construct(
        private readonly Templates $templates,
        private readonly ResponseFactoryInterface $responses,
        private readonly StreamFactoryInterface $streams,
    ) {
    }

    public function supports(mixed $result): bool
    {
        return $result instanceof View;
    }

    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        return $this->responses->createResponse()
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withBody($this->streams->createStream($this->templates->render($result)));
    }
}
```

```php
// config/converters.php
return [App\Http\ViewConverter::class];
```

## Errors

The framework catches no application exception: enable
[`gadwinn/error-catcher-middleware`](https://github.com/Gadwinn/error-catcher-middleware) to turn
them into error responses, otherwise they bubble up. The only errors turned into responses are
404 and 405 from the router (with the `Allow` header) and route parameters that do not convert
to the declared type (404).

## FrankenPHP worker mode

The `Application` keeps no state between requests: build it once and handle each request in
the worker loop.

```php
<?php
// public/index.php

use G\Framework\ApplicationFactory;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = ApplicationFactory::fromDirectory(dirname(__DIR__));

if (!function_exists('frankenphp_handle_request')) {
    $app->run();   // classic mode (PHP-FPM, Apache, built-in server)
    return;
}

$handler = static fn () => $app->run();   // request from the globals, response emitted
for ($requests = 0; frankenphp_handle_request($handler); $requests++) {
    gc_collect_cycles();
}
```

Your own services must follow the same rule: no state kept from one request to the next.

## Optional packages

Install them with Composer, then enable them in `config/middlewares.php` and, when they need
configuration, in `config/services.php`. The `gadwinn/skeleton` project ships a commented,
ready-to-enable block for each of them. Middlewares, in their order in the stack:

| Package | Use |
|---|---|
| `gadwinn/trusted-proxy-middleware` | client IP, scheme and host behind a reverse proxy |
| `gadwinn/error-catcher-middleware` | exceptions and PHP errors → error responses |
| `gadwinn/fail-sentry-middleware` | blocks the IP addresses that keep failing |
| `gadwinn/http-method-middleware` | method override (`_method`), PUT/PATCH/DELETE bodies |
| `gadwinn/csrf` | CSRF protection |

Services:

| Package | Use |
|---|---|
| `gadwinn/dotenv` | `.env` files |
| `gadwinn/psr-log` | PSR-3 logger |
| `gadwinn/psr-simple-cache` | PSR-16 cache |
| `gadwinn/psr-event-dispatcher` | PSR-14 events |
| `gadwinn/psr-clock` | PSR-20 clock |
| `gadwinn/psr-http-client` | PSR-18 HTTP client |
| `gadwinn/orm` | database |
| `gadwinn/translator` | translations |
| `gadwinn/validator` | validation |
| `gadwinn/jwt` | JSON Web Tokens |
| `gadwinn/debug` | debug bar (development only) |

Example with the proxy middleware, which needs the addresses of the proxies:

```php
// config/services.php
G\TrustedProxyMiddleware\TrustedProxyMiddleware::class
    => static fn () => new G\TrustedProxyMiddleware\TrustedProxyMiddleware(['172.18.0.0/16']),
```

## Debug bar

`gadwinn/debug` adds a bar at the bottom of the HTML pages (time, memory, request, route, logs,
SQL, dumps) and a `Server-Timing` header to every response. **Development only**: it exposes
internal data, so enable it only when `APP_ENV` is `dev`. No change to the framework is
needed, everything goes through the configuration:

```php
// config/middlewares.php
$dev = ($_ENV['APP_ENV'] ?? 'prod') === 'dev';

return [
    ...($dev ? [G\Debug\DebugBarMiddleware::class] : []),       // first: error pages get the bar
    // ... other middlewares ...
    G\Router\Middleware\RouterMiddleware::class,
    ...($dev ? [G\Debug\CaptureRequestMiddleware::class] : []), // after the router: sees the route
];
```

```php
// config/services.php
G\Debug\DebugBar::class => static fn (ContainerInterface $c) => new G\Debug\DebugBar(
    new G\Debug\Collector\TimeMemoryCollector(),
    new G\Debug\Collector\RequestCollector(),
    new G\Debug\Collector\RouteCollector(),
    $c->get(G\Debug\Collector\LogCollector::class),     // also the application's LoggerInterface
    $c->get(G\Debug\Collector\QueryCollector::class),   // logger of G\Orm\Driver\LoggingConnection
    $c->get(G\Debug\Collector\DumpCollector::class),
),
```

Both middlewares are autowired. See the README of `gadwinn/debug` for the collectors, the SQL
panel and writing your own collector.

## Development

The `gadwinn/*` packages are not on Packagist yet: `composer.json` reads them from their GitHub
repositories (`vcs` repositories, `dev-develop` branch). This will be replaced by tagged
versions.

```bash
composer install
composer test-all   # security audit, code style, static analysis, unit tests, magic numbers
```

## License

MIT
