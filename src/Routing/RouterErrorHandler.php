<?php

declare(strict_types=1);

namespace G\Framework\Routing;

use G\Router\Exception\MethodNotAllowedException;
use G\Router\Exception\NotFoundException;
use G\Router\Exception\RouterException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Error callback of the RouterMiddleware: an empty 404 response when no route matches,
 * an empty 405 response with the Allow header when the method is not allowed.
 *
 * A project replaces it by declaring its own RouterMiddleware in config/services.php.
 */
final class RouterErrorHandler
{
    public const int STATUS_NOT_FOUND = 404;
    public const int STATUS_METHOD_NOT_ALLOWED = 405;

    public function __construct(private readonly ResponseFactoryInterface $responseFactory)
    {
    }

    /**
     * @throws RouterException Any other router exception is rethrown.
     */
    public function __invoke(RouterException $exception, ServerRequestInterface $request): ResponseInterface
    {
        if ($exception instanceof MethodNotAllowedException) {
            return $this->responseFactory->createResponse(self::STATUS_METHOD_NOT_ALLOWED)
                ->withHeader('Allow', implode(', ', $exception->getAllowedMethods()));
        }

        if ($exception instanceof NotFoundException) {
            return $this->responseFactory->createResponse(self::STATUS_NOT_FOUND);
        }

        throw $exception;
    }
}
