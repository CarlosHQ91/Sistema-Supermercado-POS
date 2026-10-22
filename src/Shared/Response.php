<?php
declare(strict_types=1);

namespace App\Shared;

class Response
{
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    public static function error(string $message, int $status = 400, array $extra = []): void
    {
        self::json(array_merge([
            'ok' => false,
            'error' => $message
        ], $extra), $status);
    }

    public static function success(array $data = [], string $message = 'Operación exitosa'): void
    {
        self::json(array_merge([
            'ok' => true,
            'message' => $message
        ], $data));
    }

    public static function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }
}
