<?php

declare(strict_types=1);

namespace G\Framework\Tests;

use G\Framework\ApplicationFactory;
use G\Framework\Exception\FrameworkException;
use G\Framework\Exception\FrameworkExceptionInterface;
use G\Framework\Response\ResponseConverterInterface;
use G\Http\Message\ServerRequestFactory;
use G\Router\Middleware\RouterMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationFactoryTest extends TestCase
{
    private const array VALID = [
        'services.php' => '<?php return [];',
        'middlewares.php' => '<?php return [' . RouterMiddleware::class . '::class];',
        'routes.php' => '<?php return static function (G\Router\RouterInterface $router): void {'
            . ' $router->get("/", static fn (): string => "ok"); };',
    ];

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            foreach (glob($this->dir . '/config/*.php') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir . '/config');
            rmdir($this->dir);
        }
    }

    /**
     * @param array<string, string|null> $files Configuration files replacing the valid ones; null removes one.
     */
    private function project(array $files): string
    {
        $this->dir = sys_get_temp_dir() . '/g-framework-' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/config', 0o777, true);

        foreach (array_merge(self::VALID, $files) as $name => $content) {
            if ($content !== null) {
                file_put_contents($this->dir . '/config/' . $name, $content);
            }
        }

        return $this->dir;
    }

    public function testMinimalProject(): void
    {
        $app = ApplicationFactory::fromDirectory($this->project([]) . '/');
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));

        $this->assertSame('ok', (string) $response->getBody());
    }

    /**
     * @return array<string, array{array<string, string|null>, string}>
     */
    public static function invalidConfigurations(): array
    {
        return [
            'services missing' => [['services.php' => null], 'config/services.php'],
            'middlewares missing' => [['middlewares.php' => null], 'config/middlewares.php'],
            'routes missing' => [['routes.php' => null], 'config/routes.php'],
            'services not an array' => [['services.php' => '<?php return "x";'], 'config/services.php'],
            'services without ids' => [['services.php' => '<?php return ["Foo"];'], 'config/services.php'],
            'middlewares not a list' => [['middlewares.php' => '<?php return ["a" => "Foo"];'], 'config/middlewares.php'],
            'middleware of a wrong type' => [['middlewares.php' => '<?php return [42];'], 'config/middlewares.php'],
            'routes not a closure' => [['routes.php' => '<?php return [];'], 'config/routes.php'],
            'converters not a list' => [['converters.php' => '<?php return "x";'], 'config/converters.php'],
            'unknown middleware' => [['middlewares.php' => '<?php return ["Unknown\\\\Middleware"];'], 'Unknown\\Middleware'],
            'middleware of a wrong class' => [['middlewares.php' => '<?php return [stdClass::class];'], 'stdClass'],
            'converter of a wrong class' => [['converters.php' => '<?php return [stdClass::class];'], 'stdClass'],
        ];
    }

    /**
     * @param array<string, string|null> $files
     */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidConfiguration(array $files, string $message): void
    {
        $dir = $this->project($files);

        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage($message);

        $app = ApplicationFactory::fromDirectory($dir);
        // Converters are built on the first request
        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
    }

    public function testMissingRouterMiddleware(): void
    {
        $app = ApplicationFactory::fromDirectory($this->project(['middlewares.php' => '<?php return [];']));

        $this->expectException(FrameworkExceptionInterface::class);
        $this->expectExceptionMessage('RouterMiddleware is missing');

        $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/'));
    }

    public function testDefaultServicesAreRegistered(): void
    {
        $container = ApplicationFactory::fromDirectory($this->project([]))->getContainer();

        $this->assertInstanceOf(ResponseConverterInterface::class, $container->get(ResponseConverterInterface::class));
        $this->assertInstanceOf(RouterMiddleware::class, $container->get(RouterMiddleware::class));
    }
}
