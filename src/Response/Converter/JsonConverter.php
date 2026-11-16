<?php

declare(strict_types=1);

namespace G\Framework\Response\Converter;

use G\Framework\Response\ResponseConverterInterface;
use JsonException;
use JsonSerializable;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * An array or a JsonSerializable object becomes a JSON response (200).
 */
final class JsonConverter implements ResponseConverterInterface
{
    public const string CONTENT_TYPE = 'application/json';
    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly ResponseFactoryInterface $responseFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function supports(mixed $result): bool
    {
        return is_array($result) || $result instanceof JsonSerializable;
    }

    /**
     * @throws JsonException When the result cannot be encoded.
     */
    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory->createResponse()
            ->withHeader('Content-Type', self::CONTENT_TYPE)
            ->withBody($this->streamFactory->createStream(json_encode($result, self::FLAGS)));
    }
}
