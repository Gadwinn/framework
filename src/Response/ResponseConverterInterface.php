<?php

declare(strict_types=1);

namespace G\Framework\Response;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns the value returned by a controller into a PSR-7 response.
 */
interface ResponseConverterInterface
{
    /**
     * Tell whether this converter knows how to turn the result into a response.
     */
    public function supports(mixed $result): bool;

    /**
     * Turn the result into a response; only called when supports() returned true.
     */
    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface;
}
