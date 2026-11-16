<?php

declare(strict_types=1);

namespace G\Framework\Tests;

use G\Framework\Application;
use G\Framework\ApplicationFactory;
use G\Framework\Exception\FrameworkException;
use G\Framework\Tests\Fixtures\App\Greeter;
use G\Framework\Tests\Fixtures\App\RecordingEmitter;
use G\Http\Emitter\EmitterInterface;
use G\Http\Message\ServerRequestFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

final class ApplicationTest extends TestCase
{
    private const string PROJECT = __DIR__ . '/Fixtures/app';

    private Application $app;

    protected function setUp(): void
    {
        $this->app = ApplicationFactory::fromDirectory(self::PROJECT);
    }

    private function request(string $method, string $uri): ResponseInterface
    {
        return $this->app->handle((new ServerRequestFactory())->createServerRequest($method, $uri));
    }

    public function testHtmlResponse(): void
    {
        $response = $this->request('GET', 'http://localhost/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('<h1>Home</h1>', (string) $response->getBody());
    }

    public function testMiddlewareOfTheProjectRuns(): void
    {
        $this->assertSame('/hello/world', $this->request('GET', '/hello/world')->getHeaderLine('X-Path'));
    }

    public function testStaticStringTargetAndServiceDeclaredByTheProject(): void
    {
        $this->assertSame('Bonjour world', (string) $this->request('GET', '/hello/world')->getBody());
    }

    public function testJsonResponseWithConvertedParameterRequestAndRoute(): void
    {
        $response = $this->request('GET', '/users/42');

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"id":42,"path":"/users/42","route":"user"}', (string) $response->getBody());
    }

    public function testInvalidRouteParameterGives404(): void
    {
        $this->assertSame(404, $this->request('GET', '/users/abc')->getStatusCode());
    }

    public function testNullGives204(): void
    {
        $response = $this->request('GET', '/nothing');

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testConverterOfTheProjectComesFirst(): void
    {
        $response = $this->request('GET', '/shout/hey');

        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        $this->assertSame('HEY!', (string) $response->getBody());
    }

    public function testResponseReturnedAsIs(): void
    {
        $this->assertSame(201, $this->request('POST', '/created')->getStatusCode());
    }

    public function testInvokableController(): void
    {
        $this->assertSame('{"slug":"my-post"}', (string) $this->request('GET', '/posts/my-post')->getBody());
    }

    public function testRequestHandlerController(): void
    {
        $response = $this->request('DELETE', '/handler');

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('DELETE', $response->getHeaderLine('X-Handler'));
    }

    public function testClosureTarget(): void
    {
        $this->assertSame('{"double":2.5}', (string) $this->request('GET', '/closure/1.25')->getBody());
    }

    public function testUnknownRouteGives404(): void
    {
        $response = $this->request('GET', '/unknown');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame('/unknown', $response->getHeaderLine('X-Path'));
    }

    public function testWrongMethodGives405WithAllow(): void
    {
        $response = $this->request('POST', '/');

        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testUnsupportedResultIsAFrameworkException(): void
    {
        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage('stdClass');

        $this->request('GET', '/object');
    }

    public function testSameApplicationServesSeveralRequests(): void
    {
        $paths = ['/users/1', '/hello/a', '/unknown', '/users/2', '/'];
        $expected = [
            '{"id":1,"path":"/users/1","route":"user"}',
            'Bonjour a',
            '',
            '{"id":2,"path":"/users/2","route":"user"}',
            '<h1>Home</h1>',
        ];

        foreach ([1, 2] as $round) {
            foreach ($paths as $i => $path) {
                $response = $this->request('GET', $path);
                $this->assertSame($expected[$i], (string) $response->getBody(), "round $round, $path");
                $this->assertSame($path, $response->getHeaderLine('X-Path'));
            }
        }
    }

    public function testDefaultServiceReplacedByTheProjectAndRun(): void
    {
        $container = $this->app->getContainer();
        $emitter = $container->get(EmitterInterface::class);
        $this->assertInstanceOf(RecordingEmitter::class, $emitter);

        $this->app->run((new ServerRequestFactory())->createServerRequest('GET', '/hello/run'));

        $this->assertCount(1, $emitter->emitted);
        $this->assertSame('Bonjour run', (string) $emitter->emitted[0]->getBody());
        $this->assertInstanceOf(Greeter::class, $container->get(Greeter::class));
    }

    public function testRunBuildsTheRequestFromTheGlobals(): void
    {
        $backup = $_SERVER;
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/hello/globals', 'HTTP_HOST' => 'localhost'];

        try {
            $this->app->run();
        } finally {
            $_SERVER = $backup;
        }

        $emitter = $this->app->getContainer()->get(EmitterInterface::class);
        $this->assertInstanceOf(RecordingEmitter::class, $emitter);
        $this->assertSame('Bonjour globals', (string) $emitter->emitted[0]->getBody());
    }

    public function testHeadOnGetRoute(): void
    {
        $this->assertSame(200, $this->request('HEAD', '/')->getStatusCode());
    }
}
