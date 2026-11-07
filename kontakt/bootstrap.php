<?php
namespace Megaclean\Kontakt;

require __DIR__ . '/handler.php';

function config_path(): string
{
    $env = getenv('MEGACLEAN_KONTAKT_CONFIG');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    return dirname(__DIR__, 2) . '/megaclean-private/kontakt-config.php';
}

function session_params(): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
}

function html_out(int $status, string $text): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="de"><meta charset="utf-8"><title>Kontakt</title><p>' . $safe . '</p><p><a href="../index.html#kontakt">Zurück</a></p></html>';
}

function wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json');
}

function public_text(array $body): string
{
    if (!empty($body['accepted'])) {
        return 'Der Server hat die Anfrage angenommen. Das bestätigt nicht, dass die E-Mail angekommen ist.';
    }
    if (($body['error'] ?? '') === 'not_configured') {
        return 'Das Formular ist auf diesem Server nicht freigeschaltet. Es wurde nichts gesendet.';
    }
    return 'Die Anfrage wurde nicht angenommen. Es wurde nichts gesendet.';
}
