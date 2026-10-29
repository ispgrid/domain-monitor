<?php

namespace App\Services\Registrars;

/**
 * Known registrar and DNS providers. A new provider is one class here.
 */
class ProviderRegistry
{
    private const PROVIDERS = [
        'cloudflare' => CloudflareProvider::class,
    ];

    /**
     * @return array<string, class-string<ProviderInterface>>
     */
    public static function all(): array
    {
        return self::PROVIDERS;
    }

    public static function has(string $key): bool
    {
        return isset(self::PROVIDERS[$key]);
    }

    public static function label(string $key): string
    {
        return isset(self::PROVIDERS[$key]) ? self::PROVIDERS[$key]::label() : $key;
    }

    public static function make(string $key): ProviderInterface
    {
        if (!isset(self::PROVIDERS[$key])) {
            throw new ProviderException("Unknown provider: {$key}");
        }
        $class = self::PROVIDERS[$key];
        return new $class();
    }
}
