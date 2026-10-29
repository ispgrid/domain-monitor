<?php

namespace App\Services\Registrars;

/**
 * A registrar or DNS provider account type. Add a provider by writing one class and listing it in ProviderRegistry.
 */
interface ProviderInterface
{
    public static function key(): string;

    public static function label(): string;

    /**
     * Fields the Integrations form shows: name => [label, type (text|password), help, required]
     */
    public static function credentialFields(): array;

    /**
     * @return true|string true when the credentials work, otherwise the error to show
     */
    public function testConnection(array $creds): true|string;
}
