<?php
/**
 * Kontaktformular: Prüfung, Spam-Schutz und Aufbau der E-Mail.
 * Anfragen werden nicht gespeichert. Die IP steht nicht im Klartext.
 * Rate-Limit nur als gehashter Zähler in einer Datei.
 */
namespace Megaclean\Kontakt;

const NAME_MAX = 120;
const EMAIL_MAX = 160;
const PHONE_MAX = 40;
const MESSAGE_MAX = 4000;
const MIN_SECONDS = 3;
const MAX_AGE = 7200;
const RATE_LIMIT = 5;
const RATE_WINDOW = 3600;

const RECIPIENT = 'info@megacleanberlin.de';
const FROM = 'noreply@megacleanberlin.de';
const FROM_NAME = 'Mega Clean Berlin';
const SUBJECT = 'Anfrage über das Kontaktformular';
const HMAC_KEY = '9676ee1604b647c2536de21f4bfe8c6121efadddcc150020b279a7aa76d4da68';

function rate_dir(): string
{
    $env = getenv('MEGACLEAN_RATE_DIR');
    if (is_string($env) && $env !== '') {
        return rtrim($env, '/');
    }
    return __DIR__ . '/rate';
}

function valid_email(string $value): bool
{
    if ($value === '' || strlen($value) > EMAIL_MAX || preg_match('/[\r\n\0]/', $value)) {
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

function header_safe(string $value): bool
{
    return preg_match('/[\r\n\0]/', $value) !== 1;
}

function clean_text(string $value): string
{
    return trim(str_replace("\0", '', $value));
}

function encode_subject(string $subject): string
{
    if (preg_match('/^[\x20-\x7E]*$/', $subject) === 1) {
        return $subject;
    }
    return '=?UTF-8?B?' . base64_encode($subject) . '?=';
}

function rate_purge(string $dir, int $now): void
{
    $files = glob($dir . '/r-*');
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $raw = file_get_contents($file);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        $start = is_array($data) ? (int) ($data['start'] ?? 0) : 0;
        if ($start === 0 || ($now - $start) >= RATE_WINDOW) {
            unlink($file);
        }
    }
}

function rate_allow(string $ip, int $now): bool
{
    $dir = rate_dir();
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    if (!is_writable($dir)) {
        return false;
    }
    rate_purge($dir, $now);
    $bucket = $ip !== '' ? $ip : 'unknown';
    $hash = hash_hmac('sha256', $bucket, HMAC_KEY);
    $path = $dir . '/r-' . $hash;
    $fh = fopen($path, 'c+');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX)) {
        fclose($fh);
        return false;
    }
    $raw = stream_get_contents($fh);
    $data = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($data) || ($now - (int) ($data['start'] ?? 0)) >= RATE_WINDOW) {
        $data = ['start' => $now, 'n' => 0];
    }
    if ((int) $data['n'] >= RATE_LIMIT) {
        flock($fh, LOCK_UN);
        fclose($fh);
        return false;
    }
    $data['n'] = (int) $data['n'] + 1;
    rewind($fh);
    ftruncate($fh, 0);
    fwrite($fh, json_encode($data));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    return true;
}

/**
 * @return array{to:string,subject:string,body:string,headers:string,from:string,plain:string}
 */
function build_message(string $name, string $email, string $phone, string $message): array
{
    $plain = "Name: {$name}\nE-Mail: {$email}\nTelefon: " . ($phone === '' ? '-' : $phone) . "\n\nNachricht:\n{$message}\n";
    $fromHeader = FROM_NAME . ' <' . FROM . '>';
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n"
        . 'From: ' . $fromHeader . "\r\n"
        . 'Reply-To: ' . $email . "\r\n";
    return [
        'to' => RECIPIENT,
        'subject' => encode_subject(SUBJECT),
        'body' => quoted_printable_encode($plain),
        'headers' => $headers,
        'from' => FROM,
        'plain' => $plain,
    ];
}

function send_mail(array $packet): bool
{
    return \mail(
        $packet['to'],
        $packet['subject'],
        $packet['body'],
        $packet['headers'],
        '-f' . $packet['from']
    );
}

/**
 * @param array<string,mixed> $post
 * @param array<string,mixed> $server
 * @param null|callable(array):bool $mailer
 * @return array{status:int,body:array{ok:bool,error:?string},mailed:bool}
 */
function handle(array $post, array $server, int $now, ?callable $mailer = null): array
{
    $fail = static function (int $status, string $error): array {
        return ['status' => $status, 'body' => ['ok' => false, 'error' => $error], 'mailed' => false];
    };

    if (clean_text((string) ($post['website'] ?? '')) !== '') {
        return ['status' => 200, 'body' => ['ok' => true, 'error' => null], 'mailed' => false];
    }

    $ts = clean_text((string) ($post['ts'] ?? ''));
    if ($ts === '' || preg_match('/^[0-9]{9,12}$/', $ts) !== 1 || !header_safe($ts)) {
        return $fail(400, 'ts');
    }
    $loaded = (int) $ts;
    $age = $now - $loaded;
    if ($loaded > $now + 30 || $age > MAX_AGE) {
        return $fail(400, 'expired');
    }
    if ($age < MIN_SECONDS) {
        return $fail(429, 'too_fast');
    }

    $name = clean_text((string) ($post['name'] ?? ''));
    $email = clean_text((string) ($post['email'] ?? ''));
    $phone = clean_text((string) ($post['phone'] ?? ''));
    $message = clean_text((string) ($post['message'] ?? ''));

    if ($name === '' || strlen($name) > NAME_MAX || !header_safe($name)) {
        return $fail(400, 'name');
    }
    if (!valid_email($email)) {
        return $fail(400, 'email');
    }
    if ($phone !== '' && (strlen($phone) > PHONE_MAX || !header_safe($phone) || preg_match('/^[0-9+\-\s()\/]+$/', $phone) !== 1)) {
        return $fail(400, 'phone');
    }
    $messageForHeaderCheck = str_replace(["\r", "\n"], '', $message);
    if ($message === '' || strlen($message) > MESSAGE_MAX || !header_safe($messageForHeaderCheck)) {
        return $fail(400, 'message');
    }

    $ip = (string) ($server['REMOTE_ADDR'] ?? '');
    if (!rate_allow($ip, $now)) {
        return $fail(429, 'rate');
    }

    $packet = build_message($name, $email, $phone, $message);
    $sender = $mailer ?? static fn (array $item): bool => send_mail($item);
    if (!$sender($packet)) {
        return $fail(502, 'mail');
    }
    return ['status' => 200, 'body' => ['ok' => true, 'error' => null], 'mailed' => true];
}
