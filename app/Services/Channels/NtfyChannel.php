<?php

namespace App\Services\Channels;

use App\Services\Logger;
use GuzzleHttp\Client;

/**
 * ntfy (ntfy.sh or self-hosted): JSON publish to the server root, optional token or user/password
 */
class NtfyChannel implements NotificationChannelInterface
{
    public const DEFAULT_SERVER = 'https://ntfy.sh';

    private const PRIORITIES = ['urgent' => 5, 'high' => 4, 'normal' => 3, 'low' => 2];
    private const TAGS = ['urgent' => 'rotating_light', 'high' => 'warning', 'normal' => 'bell', 'low' => 'calendar'];

    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10, 'http_errors' => false]);
        $this->logger = new Logger('ntfy_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        $server = rtrim(trim($config['server_url'] ?? '') ?: self::DEFAULT_SERVER, '/');
        $topic = trim($config['topic'] ?? '');
        if ($topic === '') {
            $this->logger->error('ntfy configuration incomplete: no topic');
            return false;
        }

        $urgency = ChannelText::urgency($data);
        $payload = [
            'topic' => $topic,
            'title' => ChannelText::title($message, $data),
            'message' => $message,
            'priority' => isset($config['priority']) && $config['priority'] !== '' ? (int)$config['priority'] : self::PRIORITIES[$urgency],
            'tags' => [self::TAGS[$urgency]],
            'markdown' => true,
        ];
        if ($url = ChannelText::domainUrl($data)) {
            $payload['click'] = $url;
        }

        $headers = [];
        if (!empty($config['access_token'])) {
            $headers['Authorization'] = 'Bearer ' . $config['access_token'];
        } elseif (!empty($config['username'])) {
            $headers['Authorization'] = 'Basic ' . base64_encode($config['username'] . ':' . ($config['password'] ?? ''));
        }

        $endpoint = $server . '/';
        try {
            $response = $this->client->post($endpoint, [
                'headers' => $headers,
                'json' => $payload,
            ] + WebhookUrlGuard::requestOptions($endpoint));

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $this->logger->info('ntfy message sent', ['status' => $status, 'priority' => $payload['priority']]);
                return true;
            }

            $this->logger->error('ntfy refused the message', [
                'status' => $status,
                'response' => mb_substr((string)$response->getBody(), 0, 500),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('ntfy send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
