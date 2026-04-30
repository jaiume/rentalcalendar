<?php

namespace App\Exceptions;

/**
 * Thrown by PortalGroupResolver when the request hostname matches neither
 * an active guest portal group nor the hostname derived from
 * [portal] admin_url. Mapped to HTTP 404 by HostnameRoutingMiddleware.
 */
class PortalNotFoundException extends \RuntimeException
{
}
