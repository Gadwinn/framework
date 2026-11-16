<?php

declare(strict_types=1);

namespace G\Framework\Tests\Response;

use ArrayObject;
use G\Framework\Exception\FrameworkException;
use G\Framework\Response\Converter\HtmlConverter;
use G\Framework\Response\Converter\JsonConverter;
use G\Framework\Response\Converter\NoContentConverter;
use G\Framework\Response\Converter\PsrResponseConverter;
use G\Framework\Response\ResponseConverter;
use G\Framework\Tests\Fixtures\App\Shout;
use G\Http\Message\ResponseFactory;
use G\Http\Message\ServerRequestFactory;
use G\Http\Message\StreamFactory;
use JsonException;
use JsonSerializable;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class ConvertersTest extends TestCase
{
    private ServerRequestInterface $request;

    protected function setUp(): void
    {
        $this->request = (new ServerRequestFactory())->createServerRequest('GET', '/');
    }

    private function chain(): ResponseConverter
    {
        return new ResponseConverter([
            new PsrResponseConverter(),
            new HtmlConverter(new ResponseFactory(), new StreamFactory()),
            new JsonConverter(new ResponseFactory(), new StreamFactory()),
            new NoContentConverter(new ResponseFactory()),
        ]);
    }

    public function testResponseIsReturnedAsIs(): void
    {
        $response = (new ResponseFactory())->createResponse(418);

        $this->assertSame($response, $this->chain()->convert($response, $this->request));
    }

    public function testStringAndStringable(): void
    {
        foreach (['<p>é</p>', new Shout('<p>é</p>')] as $result) {
            $response = $this->chain()->convert($result, $this->request);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
            $this->assertSame('<p>é</p>', (string) $response->getBody());
        }
    }

    public function testArrayAndJsonSerializable(): void
    {
        $serializable = new class implements JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['url' => 'https://example.com/é'];
            }
        };

        $this->assertSame('[1,2]', (string) $this->chain()->convert([1, 2], $this->request)->getBody());

        $response = $this->chain()->convert($serializable, $this->request);
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"url":"https://example.com/é"}', (string) $response->getBody());
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(JsonException::class);

        $this->chain()->convert(["\xB1\x31"], $this->request);
    }

    public function testNullGivesNoContent(): void
    {
        $response = $this->chain()->convert(null, $this->request);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testUnsupportedResult(): void
    {
        $this->assertFalse($this->chain()->supports(42));
        $this->assertFalse($this->chain()->supports(new ArrayObject()));

        $this->expectException(FrameworkException::class);
        $this->expectExceptionMessage('int');

        $this->chain()->convert(42, $this->request);
    }

    public function testFirstSupportingConverterWins(): void
    {
        $json = new JsonConverter(new ResponseFactory(), new StreamFactory());
        $html = new HtmlConverter(new ResponseFactory(), new StreamFactory());
        $shout = new Shout('x');

        $this->assertSame('text/html; charset=utf-8', (new ResponseConverter([$html, $json]))
            ->convert($shout, $this->request)->getHeaderLine('Content-Type'));
        $this->assertFalse((new ResponseConverter([]))->supports(null));
    }
}
