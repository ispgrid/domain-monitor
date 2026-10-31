<?php

namespace App\Services\Channels;

use App\Services\Logger;
use GuzzleHttp\Client;

/**
 * MSG91 SMS through a Flow template: POST https://control.msg91.com/api/v5/flow
 *
 * SMS in India must match a DLT-approved template, so the alert can't be sent as free
 * text. The template gets two short values: the domain and a few words about the alert.
 */
class Msg91Channel implements NotificationChannelInterface
{
    private const API_URL = 'https://control.msg91.com/api/v5/flow';
    // DLT allows up to 30 characters in each {#var#}
    public const DEFAULT_MAX_LENGTH = 30;

    private Client $client;
    private Logger $logger;

    public function __construct()
    {
        $this->client = new Client(['timeout' => 15, 'http_errors' => false]);
        $this->logger = new Logger('msg91_channel');
    }

    public function send(array $config, string $message, array $data = []): bool
    {
        $authKey = trim($config['auth_key'] ?? '');
        $templateId = trim($config['template_id'] ?? '');
        $numbers = ChannelText::phoneNumbers((string)($config['recipients'] ?? ''));
        if ($authKey === '' || $templateId === '' || !$numbers) {
            $this->logger->error('MSG91 configuration incomplete');
            return false;
        }

        $max = (int)($config['max_length'] ?? self::DEFAULT_MAX_LENGTH) ?: self::DEFAULT_MAX_LENGTH;
        $domainVar = trim($config['domain_var'] ?? '') ?: 'var1';
        $alertVar = trim($config['alert_var'] ?? '') ?: 'var2';
        $domain = (string)($data['domain'] ?? \App\Models\Setting::appName());
        $values = [
            $domainVar => mb_strlen($domain) > $max ? mb_substr($domain, 0, $max) : $domain,
            $alertVar => ChannelText::shortAlert($message, $data, $max),
        ];

        $recipients = array_map(fn($number) => ['mobiles' => $number] + $values, $numbers);

        try {
            $response = $this->client->post(self::API_URL, [
                'headers' => ['authkey' => $authKey, 'Accept' => 'application/json'],
                'json' => ['template_id' => $templateId, 'short_url' => '0', 'recipients' => $recipients],
            ]);

            $status = $response->getStatusCode();
            $body = json_decode((string)$response->getBody(), true) ?: [];
            if ($status >= 200 && $status < 300 && ($body['type'] ?? '') === 'success') {
                $this->logger->info('MSG91 SMS sent', ['recipients' => count($numbers), 'request_id' => $body['message'] ?? null]);
                return true;
            }

            $this->logger->error('MSG91 refused the SMS', [
                'status' => $status,
                'response' => mb_substr((string)json_encode($body), 0, 500),
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('MSG91 send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
