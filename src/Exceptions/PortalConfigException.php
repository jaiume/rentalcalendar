<?php

namespace App\Exceptions;

/**
 * Thrown by PortalGroupResolver / PortalGroupConfigSchema when the
 * per-portal config file is missing or fails schema validation.
 * Mapped to HTTP 503 by HostnameRoutingMiddleware (the portal exists
 * but isn't usable until an admin fixes the config).
 */
class PortalConfigException extends \RuntimeException
{
}
