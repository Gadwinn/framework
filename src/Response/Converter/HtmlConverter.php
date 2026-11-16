<?php

declare(strict_types=1);

namespace G\Framework\Response\Converter;

use G\Framework\Response\ResponseConverterInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Stringable;

/**
 * A string or a Stringable object becomes an HTML response (200).
 */
final class HtmlConverter implements ResponseConverterInterface
{
    public const string CONTENT_TYPE = 'text/html; charset=utf-8';

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function supports(mixed $result): bool
    {
        return is_string($result) || $result instanceof Stringable;
    }

    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        assert(is_string($result) || $result instanceof Stringable);

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withBody($this->streamFactory->createStream((string) $result));
    }
}
