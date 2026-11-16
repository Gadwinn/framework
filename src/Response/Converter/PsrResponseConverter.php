<?php

declare(strict_types=1);

namespace G\Framework\Response\Converter;

use G\Framework\Response\ResponseConverterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A PSR-7 response is returned as is.
 */
final class PsrResponseConverter implements ResponseConverterInterface
{
    public function supports(mixed $result): bool
    {
        return $result instanceof ResponseInterface;
    }

    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        assert($result instanceof ResponseInterface);

        return $result;
    }
}
