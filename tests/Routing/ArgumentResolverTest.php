<?php

declare(strict_types=1);

namespace G\Framework\Tests\Routing;

use Closure;
use Countable;
use G\Container\Container;
use G\Framework\Exception\FrameworkException;
use G\Framework\Routing\ArgumentResolver;
use G\Framework\Routing\InvalidRouteParameterException;
use G\Framework\Tests\Fixtures\App\Greeter;
use G\Http\Message\ServerRequestFactory;
use G\Router\Route\Route;
use G\Router\Route\RouteInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionFunction;
use Stringable;

final class ArgumentResolverTest extends TestCase
{
    private Container $container;
    private ServerRequestInterface $request;
    private RouteInterface $route;

    protected function setUp(): void
    {
        $this->container = new Container();
        $this->request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $this->route = (new Route('GET', '/', 'target', ['name' => 'r']))
            ->setParams(['id' => '42', 'price' => '9.5', 'flag' => 'true', 'name' => 'Ann']);
    }

    /**
     * @return list<mixed>
     */
    private function resolve(Closure $function): array
    {
        return (new ArgumentResolver($this->container))
            ->resolve(new ReflectionFunction($function), $this->request, $this->route);
    }

    public function testRequestAndRouteByType(): void
    {
        $args = $this->resolve(static fn (RouteInterface $r, ServerRequestInterface $q, MessageInterface $m) => null);

        $this->assertSame([$this->route, $this->request, $this->request], $args);
    }

    public function testRouteParametersByNameAreConverted(): void
    {
        $args = $this->resolve(static fn (int $id, float $price, bool $flag, string $name) => null);

        $this->assertSame([42, 9.5, true, 'Ann'], $args);
    }

    public function testUntypedAndMixedRouteParametersStayStrings(): void
    {
        $this->assertSame(['42'], $this->resolve(static fn ($id) => null));
        $this->assertSame(['42'], $this->resolve(static fn (mixed $id) => null));
    }

    public function testNullableScalarRouteParameter(): void
    {
        $this->assertSame([42], $this->resolve(static fn (?int $id) => null));
    }

    /**
     * @return array<string, array{Closure}>
     */
    public static function invalidParameters(): array
    {
        return [
            'int' => [static fn (int $name) => null],
            'float' => [static fn (float $name) => null],
            'bool' => [static fn (bool $name) => null],
        ];
    }

    #[DataProvider('invalidParameters')]
    public function testInconvertibleRouteParameter(Closure $function): void
    {
        $this->expectException(InvalidRouteParameterException::class);
        $this->expectExceptionMessage("'name'");

        $this->resolve($function);
    }

    public function testServicesFromTheContainer(): void
    {
        $this->container->set(Greeter::class, static fn (): Greeter => new Greeter('Hi'));

        [$greeter] = $this->resolve(static fn (Greeter $greeter) => null);

        $this->assertInstanceOf(Greeter::class, $greeter);
        $this->assertSame('Hi you', $greeter->greet('you'));
    }

    public function testRouteParameterWinsOverTheContainerOnlyForScalarTypes(): void
    {
        // "name" is a route parameter, but a class type is resolved by the container
        $this->container->set(Greeter::class, static fn (): Greeter => new Greeter('Hi'));

        [$name] = $this->resolve(static fn (Greeter $name) => null);

        $this->assertInstanceOf(Greeter::class, $name);
    }

    public function testDefaultValueThenNull(): void
    {
        $args = $this->resolve(static fn (string $missing = 'default', ?Countable $countable = null, ?Stringable $s = null) => null);

        $this->assertSame(['default', null, null], $args);
    }

    public function testNullableWithoutDefault(): void
    {
        $this->assertSame([null], $this->resolve(static fn (?Countable $countable) => null));
    }

    public function testUnionTypeIsIgnored(): void
    {
        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage('$id');

        $this->resolve(static fn (int|string $id) => null);
    }

    public function testUnresolvableParameterNamesTheController(): void
    {
        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage("'\$missing' of the Closure defined in ArgumentResolverTest.php");

        $this->resolve(static fn (string $missing) => null);
    }

    public function testVariadicStopsTheResolution(): void
    {
        $this->assertSame([42], $this->resolve(static fn (int $id, string ...$rest) => null));
    }
}
