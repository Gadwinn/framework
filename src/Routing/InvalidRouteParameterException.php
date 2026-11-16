<?php

declare(strict_types=1);

namespace G\Framework\Routing;

use G\Framework\Exception\FrameworkException;

/**
 * A route parameter cannot be converted to the type declared by the controller
 * (e.g. "abc" for an int): the RouteDispatcher answers 404.
 *
 * @internal
 */
final class InvalidRouteParameterException extends FrameworkException
{
}
