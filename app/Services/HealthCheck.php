<?php

namespace App\Services;

/**
 * GET /health for uptime monitors and container health checks.
 * No session, no login, and nothing private in the answer:
 * 200 {"status":"ok"} when the database answers, 503 {"status":"error"} when it doesn't.
 * Before installation it answers 200 {"status":"setup"} so a new container isn't marked unhealthy.
 */
class HealthCheck
{
    public static function respond(string $appRoot): never
    {
        $body = ['status' => 'ok'];
        $code = 200;

        if (!file_exists(rtrim($appRoot, '/\\') . '/.installed')) {
            $body['status'] = 'setup';
        } else {
            try {
                \Core\Database::getConnection()->query('SELECT 1')->fetchColumn();
                $body['database'] = 'ok';
            } catch (\Throwable $e) {
                $code = 503;
                $body = ['status' => 'error', 'database' => 'error'];
            }
        }

        http_response_code($code);
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo json_encode($body);
        }
        exit;
    }
}
