<?php

declare(strict_types=1);

namespace G\Framework\Exception;

use RuntimeException;

/**
 * Configuration or resolution error: missing configuration file, invalid route target,
 * unresolvable controller argument, controller result no converter supports...
 */
class FrameworkException extends RuntimeException implements FrameworkExceptionInterface
{
}
