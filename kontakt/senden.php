<?php
namespace Megaclean\Kontakt;

require __DIR__ . '/handler.php';

function json_out(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
}

function html_out(int $status, string $text): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="de"><meta charset="utf-8"><title>Kontakt</title><p>'
        . $safe
        . '</p><p><a href="../index.html#kontakt">Zurück zum Formular</a></p>'
        . '<p>Schriftlich erreichen Sie uns über <a href="mailto:info@megacleanberlin.de">info@megacleanberlin.de</a>.</p></html>';
}

function wants_json(): bool
{
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    return str_contains($accept, 'application/json');
}

function public_text(array $body): string
{
    if (!empty($body['ok'])) {
        return 'Vielen Dank. Ihre Anfrage wurde übermittelt. Wenn Sie nichts von uns hören, schreiben Sie uns bitte an info@megacleanberlin.de.';
    }
    $error = (string) ($body['error'] ?? '');
    return match ($error) {
        'too_fast' => 'Bitte etwas warten und das Formular erneut senden.',
        'expired', 'ts' => 'Das Formular war zu lange geöffnet oder unvollständig. Bitte die Seite neu laden und erneut senden.',
        'email' => 'Bitte eine gültige E-Mail-Adresse eintragen.',
        'name' => 'Bitte einen Namen eintragen.',
        'phone' => 'Die Telefonnummer bitte nur mit Ziffern, Leerzeichen und + / - ( ).',
        'message' => 'Bitte eine Nachricht eintragen.',
        'rate' => 'Es wurden zu viele Anfragen gesendet. Bitte später erneut versuchen oder uns per E-Mail schreiben.',
        default => 'Die Anfrage konnte nicht übermittelt werden. Bitte schreiben Sie uns an info@megacleanberlin.de.',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $body = ['ok' => false, 'error' => 'method'];
    wants_json() ? json_out(405, $body) : html_out(405, public_text($body));
    exit;
}

$result = handle($_POST, $_SERVER, time());
$public = ['ok' => $result['body']['ok'], 'error' => $result['body']['error']];
if (wants_json()) {
    json_out($result['status'], $public);
} else {
    html_out($result['status'], public_text($public));
}
