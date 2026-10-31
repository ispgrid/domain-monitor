<?php

namespace App\Services\Channels;

use App\Services\Logger;
use GuzzleHttp\Client;

/**
 * WACRM (self-hosted WhatsApp CRM): POST {base}/api/v1/messages with an API key.
 *
 * WhatsApp only takes free text within 24 hours of the person's last message, so alerts
 * normally go out as an approved template whose one variable holds the alert on one line.
 */
class WacrmChannel implements NotificationChannelInterface
{
    // Room for the template's own text within WhatsApp's 1024 character body limit
    private const TEMPLATE_VALUE_MAX = 700;
    private const TEXT_MAX = 4000;

    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 15, 'http_errors' => false]);
        $this->logger = new Logger('wacrm_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        $base = rtrim(trim($config['base_url'] ?? ''), '/');
        $apiKey = trim($config['api_key'] ?? '');
        $numbers = ChannelText::phoneNumbers((string)($config['recipients'] ?? ''));
        $mode = ($config['mode'] ?? 'template') === 'text' ? 'text' : 'template';
        if ($base === '' || $apiKey === '' || !$numbers || ($mode === 'template' && empty($config['template_name']))) {
            $this->logger->error('WACRM configuration incomplete');
            return false;
        }

        if ($mode === 'template') {
            $body = [
                'type' => 'template',
                'template' => [
                    'name' => $config['template_name'],
                    'language' => $config['template_language'] ?? 'en_US',
                    'params' => [ChannelText::oneLine($message, self::TEMPLATE_VALUE_MAX)],
                ],
            ];
        } else {
            // WhatsApp bold is a single asterisk
            $text = str_replace('**', '*', trim($message));
            if ($url = ChannelText::domainUrl($data)) {
                $text .= "\n\n" . $url;
            }
            $body = ['type' => 'text', 'text' => mb_substr($text, 0, self::TEXT_MAX)];
        }

        $endpoint = $base . '/api/v1/messages';
        $sent = 0;
        foreach ($numbers as $number) {
            try {
                $response = $this->client->post($endpoint, [
                    'headers' => ['Authorization' => 'Bearer ' . $apiKey, 'Accept' => 'application/json'],
                    'json' => ['to' => '+' . $number] + $body,
                ] + WebhookUrlGuard::requestOptions($endpoint));

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    $sent++;
                    continue;
                }

                $error = json_decode((string)$response->getBody(), true)['error'] ?? [];
                $this->logger->error('WACRM refused the message', [
                    'status' => $status,
                    'to' => $this->maskNumber($number),
                    'code' => $error['code'] ?? null,
                    'message' => isset($error['message']) ? mb_substr((string)$error['message'], 0, 300) : null,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('WACRM send failed', ['to' => $this->maskNumber($number), 'error' => $e->getMessage()]);
            }
        }

        if ($sent > 0) {
            $this->logger->info('WACRM message sent', ['mode' => $mode, 'sent' => $sent, 'recipients' => count($numbers)]);
        }
        // Counted as delivered only when every number got it, so a partial failure shows in the logs
        return $sent === count($numbers);
    }

    private function maskNumber(string $number): string
    {
        return str_repeat('*', max(0, strlen($number) - 4)) . substr($number, -4);
    }
}
