<?php

namespace App\Services\Channels;

use App\Services\Logger;
use GuzzleHttp\Client;

/**
 * WAHA (self-hosted WhatsApp HTTP API): POST {base}/api/sendText for each chat.
 * It sends from a linked WhatsApp account, so plain text works without templates.
 */
class WahaChannel implements NotificationChannelInterface
{
    public const DEFAULT_SESSION = 'default';
    private const TEXT_MAX = 4000;

    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 15, 'http_errors' => false]);
        $this->logger = new Logger('waha_channel');
    }

    /**
     * WhatsApp chat IDs from a comma or line separated list: numbers become <digits>@c.us, group IDs (...@g.us) stay as they are
     */
    public static function chatIds(string $list): array
    {
        $ids = [];
        foreach (preg_split('/[,;\r\n]+/', $list) as $entry) {
            $entry = trim($entry);
            if (preg_match('/^[A-Za-z0-9._-]+@(c\.us|g\.us|lid|newsletter)$/', $entry)) {
                $ids[] = $entry;
                continue;
            }
            $digits = preg_replace('/[^\d]/', '', $entry);
            if (strlen($digits) >= 8 && strlen($digits) <= 15) {
                $ids[] = $digits . '@c.us';
            }
        }
        return array_values(array_unique($ids));
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        $base = rtrim(trim($config['base_url'] ?? ''), '/');
        $chats = self::chatIds((string)($config['recipients'] ?? ''));
        if ($base === '' || !$chats) {
            $this->logger->error('WAHA configuration incomplete');
            return false;
        }

        // WhatsApp bold is a single asterisk
        $text = str_replace('**', '*', trim($message));
        if ($url = ChannelText::domainUrl($data)) {
            $text .= "\n\n" . $url;
        }
        $text = mb_substr($text, 0, self::TEXT_MAX);

        $headers = ['Accept' => 'application/json'];
        if (!empty($config['api_key'])) {
            $headers['X-Api-Key'] = $config['api_key'];
        }

        $endpoint = $base . '/api/sendText';
        $sent = 0;
        foreach ($chats as $chatId) {
            try {
                $response = $this->client->post($endpoint, [
                    'headers' => $headers,
                    'json' => [
                        'session' => ($config['session'] ?? '') ?: self::DEFAULT_SESSION,
                        'chatId' => $chatId,
                        'text' => $text,
                        'linkPreview' => false,
                    ],
                ] + WebhookUrlGuard::requestOptions($endpoint));

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $sent++;
                    continue;
                }

                $this->logger->error('WAHA refused the message', [
                    'status' => $status,
                    'chat' => $this->maskChat($chatId),
                    'response' => mb_substr((string)$response->getBody(), 0, 300),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('WAHA send failed', ['chat' => $this->maskChat($chatId), 'error' => $e->getMessage()]);
            }
        }

        if ($sent > 0) {
            $this->logger->info('WAHA message sent', ['sent' => $sent, 'chats' => count($chats)]);
        }
        // Counted as delivered only when every chat got it, so a partial failure shows in the logs
        return $sent === count($chats);
    }

    private function maskChat(string $chatId): string
    {
        [$id, $suffix] = explode('@', $chatId, 2) + [1 => ''];
        return str_repeat('*', max(0, strlen($id) - 4)) . substr($id, -4) . '@' . $suffix;
    }
}
