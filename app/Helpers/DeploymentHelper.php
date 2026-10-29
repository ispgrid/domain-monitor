<?php

namespace App\Helpers;

/**
 * How the app is deployed. The Docker image writes DOCKER=true into .env on every start;
 * other installs don't have the line and keep the normal behaviour.
 */
class DeploymentHelper
{
    public static function isDocker(): bool
    {
        $value = $_ENV['DOCKER'] ?? $_SERVER['DOCKER'] ?? getenv('DOCKER');
        return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Commands shown instead of the in-app updater when running the Docker image
     */
    public static function dockerUpdateCommands(): array
    {
        return [
            'docker compose pull',
            'docker compose up -d',
        ];
    }
}
