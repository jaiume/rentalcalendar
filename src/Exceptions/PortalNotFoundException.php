<?php

namespace App\Exceptions;

/**
 * Thrown by PortalGroupResolver when the request hostname matches neither
 * an active guest portal group nor an entry in [portal] admin_hostnames.
 * Mapped to HTTP 404 by HostnameRoutingMiddleware.
 */
class PortalNotFoundException extends \RuntimeException
{
}
