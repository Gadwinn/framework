<?php

declare(strict_types=1);

namespace G\Framework\Response\Converter;

use G\Framework\Response\ResponseConverterInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Null (a controller that returns nothing) becomes an empty 204 response.
 */
final class NoContentConverter implements ResponseConverterInterface
{
    public const int STATUS_CODE = 204;

    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    public function supports(mixed $result): bool
    {
        return $result === null;
    }

    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        return $this->responseFactory->createResponse(self::STATUS_CODE);
    }
}
