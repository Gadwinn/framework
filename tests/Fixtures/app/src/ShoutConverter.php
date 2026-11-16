<?php

declare(strict_types=1);

namespace G\Framework\Tests\Fixtures\App;

use G\Framework\Response\ResponseConverterInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

final class ShoutConverter implements ResponseConverterInterface
{
    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function supports(mixed $result): bool
    {
        return $result instanceof Shout;
    }

    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        assert($result instanceof Shout);

        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($this->streamFactory->createStream(strtoupper($result->word) . '!'));
    }
}
