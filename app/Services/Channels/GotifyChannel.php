<?php

namespace App\Services\Channels;

use App\Services\Logger;
use GuzzleHttp\Client;

/**
 * Gotify (self-hosted push): POST {server}/message with an application token
 */
class GotifyChannel implements NotificationChannelInterface
{
    private const PRIORITIES = ['urgent' => 8, 'high' => 6, 'normal' => 4, 'low' => 2];

    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 10, 'http_errors' => false]);
        $this->logger = new Logger('gotify_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        $server = rtrim(trim($config['server_url'] ?? ''), '/');
        $token = trim($config['app_token'] ?? '');
        if ($server === '' || $token === '') {
            $this->logger->error('Gotify configuration incomplete');
            return false;
        }

        $priority = isset($config['priority']) && $config['priority'] !== ''
            ? (int)$config['priority']
            : self::PRIORITIES[ChannelText::urgency($data)];

        $extras = ['client::display' => ['contentType' => 'text/markdown']];
        if ($url = ChannelText::domainUrl($data)) {
            $extras['client::notification'] = ['click' => ['url' => $url]];
        }

        $endpoint = $server . '/message';
        try {
            $response = $this->client->post($endpoint, [
                'headers' => ['X-Gotify-Key' => $token],
                'json' => [
                    'title' => ChannelText::title($message, $data),
                    'message' => $message,
                    'priority' => $priority,
                    'extras' => $extras,
                ],
            ] + WebhookUrlGuard::requestOptions($endpoint));

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $this->logger->info('Gotify message sent', ['status' => $status, 'priority' => $priority]);
                return true;
            }

            $this->logger->error('Gotify refused the message', [
                'status' => $status,
                'response' => mb_substr((string)$response->getBody(), 0, 500),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('Gotify send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
