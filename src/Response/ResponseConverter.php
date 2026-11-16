<?php

declare(strict_types=1);

namespace G\Framework\Response;

use G\Framework\Exception\FrameworkException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Chain of converters: the first one that supports the result converts it.
 */
final class ResponseConverter implements ResponseConverterInterface
{
    /**
     * @param list<ResponseConverterInterface> $converters In order of priority.
     */
    public function __construct(private readonly array $converters)
    {
    }

    public function supports(mixed $result): bool
    {
        return $this->find($result) !== null;
    }

    /**
     * @throws FrameworkException When no converter supports the result.
     */
    public function convert(mixed $result, ServerRequestInterface $request): ResponseInterface
    {
        $converter = $this->find($result)
            ?? throw new FrameworkException(
                'No response converter supports a result of type ' . get_debug_type($result)
            );

        return $converter->convert($result, $request);
    }

    private function find(mixed $result): ?ResponseConverterInterface
    {
        foreach ($this->converters as $converter) {
            if ($converter->supports($result)) {
                return $converter;
            }
        }

        return null;
    }
}
