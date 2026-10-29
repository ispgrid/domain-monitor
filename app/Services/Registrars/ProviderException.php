<?php

namespace App\Services\Registrars;

/**
 * A provider API call failed. The message is safe to show and store: it never contains credentials.
 */
class ProviderException extends \RuntimeException
{
}
