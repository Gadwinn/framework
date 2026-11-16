<?php

declare(strict_types=1);

namespace G\Framework\Tests\Routing;

use G\Framework\Routing\RouterErrorHandler;
use G\Http\Message\ResponseFactory;
use G\Http\Message\ServerRequestFactory;
use G\Router\Exception\MethodNotAllowedException;
use G\Router\Exception\NotFoundException;
use G\Router\Exception\RouterException;
use PHPUnit\Framework\TestCase;

final class RouterErrorHandlerTest extends TestCase
{
    public function testNotFound(): void
    {
        $response = (new RouterErrorHandler(new ResponseFactory()))(
            new NotFoundException('Route not found', 404),
            (new ServerRequestFactory())->createServerRequest('GET', '/')
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testMethodNotAllowed(): void
    {
        $response = (new RouterErrorHandler(new ResponseFactory()))(
            new MethodNotAllowedException('Method not allowed', 405, ['GET', 'POST']),
            (new ServerRequestFactory())->createServerRequest('PUT', '/')
        );

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, POST', $response->getHeaderLine('Allow'));
    }

    public function testOtherRouterExceptionsAreRethrown(): void
    {
        $exception = new RouterException('other');

        $this->expectExceptionObject($exception);

        (new RouterErrorHandler(new ResponseFactory()))($exception, (new ServerRequestFactory())->createServerRequest('GET', '/'));
    }
}
