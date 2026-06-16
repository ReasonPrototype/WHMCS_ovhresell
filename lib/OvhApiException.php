<?php

namespace OvhVps;

/**
 * Thrown when an OVH API call fails. The code carries the HTTP status when
 * available (e.g. 403 for forbidden checkout / missing scopes).
 */
class OvhApiException extends \RuntimeException
{
}
