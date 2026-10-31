<?php

namespace App\Services\Channels;

/**
 * Turns an alert into the shorter pieces some channels need: a title, plain text,
 * one line (WhatsApp template values) or a few words (SMS template values)
 */
final class ChannelText
{
    /**
     * The alert's title: its subject when it has one, otherwise its first line
     */
    public static function title(string $message, array $data): string
    {
        if (!empty($data['subject'])) {
            return self::plain((string)$data['subject']);
        }
        return self::plain(strtok(trim($message), "\n") ?: \App\Models\Setting::appName());
    }

    /**
     * The message without Markdown bold, italics or code marks
     */
    public static function plain(string $text): string
    {
        return trim(str_replace(['**', '__', '`'], '', $text));
    }

    /**
     * Everything on one line (WhatsApp template values may not hold line breaks or long runs of spaces)
     */
    public static function oneLine(string $text, int $max): string
    {
        $lines = array_filter(array_map('trim', preg_split('/\R+/u', self::plain($text))), 'strlen');
        $line = preg_replace('/\s+/u', ' ', implode(' | ', $lines));
        return self::cut($line, $max);
    }

    /**
     * A few words for an SMS template value, e.g. "expires in 7 days" or "SSL certificate expired"
     */
    public static function shortAlert(string $message, array $data, int $max): string
    {
        $domain = (string)($data['domain'] ?? '');

        if (!empty($data['subject'])) {
            $text = self::plain((string)$data['subject']);
            if ($domain !== '') {
                $text = str_ireplace($domain, '', $text);
            }
        } elseif (!empty($data['new_status'])) {
            $text = 'status ' . \App\Services\NotificationService::getStatusLabel((string)$data['new_status']);
        } elseif (isset($data['days_left']) && is_numeric($data['days_left'])) {
            $days = (int)$data['days_left'];
            $text = match (true) {
                $days < 0 => 'expired ' . -$days . ' day' . ($days === -1 ? '' : 's') . ' ago',
                $days === 0 => 'expired',
                $days === 1 => 'expires tomorrow',
                default => "expires in $days days",
            };
        } else {
            $text = self::title($message, []);
        }

        // Drop emoji and leftover punctuation from around the removed domain name
        $text = preg_replace('/[^\p{L}\p{N}\s.,:()\/\'-]/u', '', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text), " :-'");
        return self::cut($text !== '' ? $text : 'alert', $max);
    }

    /**
     * How urgent the alert is: urgent, high, normal or low
     */
    public static function urgency(array $data): string
    {
        if (isset($data['days_left']) && is_numeric($data['days_left'])) {
            $days = (int)$data['days_left'];
            return $days <= 1 ? 'urgent' : ($days <= 7 ? 'high' : ($days <= 14 ? 'normal' : 'low'));
        }
        if (in_array($data['new_status'] ?? '', ['expired', 'redemption_period', 'pending_delete', 'available'], true)) {
            return 'high';
        }
        return 'normal';
    }

    /**
     * Link to the domain in the app, when the alert is about one
     */
    public static function domainUrl(array $data): ?string
    {
        if (empty($data['domain_id'])) {
            return null;
        }
        $base = $_ENV['APP_URL'] ?? '';
        return $base !== '' ? rtrim($base, '/') . '/domains/' . (int)$data['domain_id'] : null;
    }

    /**
     * Phone numbers from a comma, semicolon or line separated list, digits only (spaces, dashes and a leading + are dropped)
     */
    public static function phoneNumbers(string $list): array
    {
        $numbers = [];
        foreach (preg_split('/[,;\r\n]+/', $list) as $number) {
            $digits = preg_replace('/[^\d]/', '', $number);
            if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                $numbers[] = $digits;
            }
        }
        return array_values(array_unique($numbers));
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, max(1, $max - 3))) . '...' : $text;
    }
}
