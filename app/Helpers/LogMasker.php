<?php

namespace App\Helpers;

/**
 * Hides passwords, tokens and webhook secrets in log text before it is shown or stored.
 */
class LogMasker
{
    private const MASK = '***';

    public static function mask(string $text, array $secrets = []): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $text = str_replace($secret, self::MASK, $text);
            }
        }

        $patterns = [
            // key=value, key: value, "key":"value" for secret-looking keys
            '/(\b(?:password|passwd|pwd|secret|client_secret|token|access_token|refresh_token|api_key|apikey|api_token|bot_token|app_token|user_key|authorization|webhook_url)\b["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,&}\]]+)/i'
                => '$1"' . self::MASK . '"',
            // Authorization headers
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 ' . self::MASK,
            // Credentials in URLs: scheme://user:pass@host
            '#(\b[a-z][a-z0-9+.-]*://)[^\s/@:]+:[^\s/@]+@#i' => '$1' . self::MASK . '@',
            // Telegram bot tokens in API URLs
            '#/bot\d+:[A-Za-z0-9_-]+#' => '/bot' . self::MASK,
            // Slack, Discord and Mattermost webhook URLs carry their secret in the path
            '#(hooks\.slack\.com/services/)[^\s"\'<>]+#i' => '$1' . self::MASK,
            '#(discord(?:app)?\.com/api/webhooks/)[^\s"\'<>]+#i' => '$1' . self::MASK,
            '#(/hooks/)[a-z0-9]{20,}#i' => '$1' . self::MASK,
            // MySQL client password flags
            '/(--password=)("[^"]*"|\'[^\']*\'|\S+)/' => '$1' . self::MASK,
            '/(\s-p)(?=[^\s-])("[^"]*"|\'[^\']*\'|\S+)/' => '$1' . self::MASK,
        ];

        return preg_replace(array_keys($patterns), array_values($patterns), $text) ?? $text;
    }
}
