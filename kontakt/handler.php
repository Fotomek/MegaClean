<?php
/**
 * Kontaktformular-Logik. Versendet nichts, solange die Konfiguration nicht
 * ausdrücklich freigeschaltet ist. mail() wird nur von PhpMailTransport
 * aufgerufen und im Test durch die Namensraum-Funktion blockiert.
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

interface Transport
{
    /** @param array{to:string,subject:string,body:string,headers:string,from:string} $message */
    public function accept(array $message): bool;
}

class FakeTransport implements Transport
{
    /** @var list<array{to:string,subject:string,body:string,headers:string,from:string}> */
    public array $accepted = [];

    public function accept(array $message): bool
    {
        $this->accepted[] = $message;
        return true;
    }
}

class PhpMailTransport implements Transport
{
    public function accept(array $message): bool
    {
        if (getenv('MEGACLEAN_FORBID_REAL_MAIL') === '1') {
            throw new \RuntimeException('real mail forbidden');
        }
        return mail(
            $message['to'],
            $message['subject'],
            $message['body'],
            $message['headers'],
            '-f' . $message['from']
        );
    }
}

function load_config(?string $path): array
{
    if ($path === null || $path === '' || !is_file($path)) {
        return [];
    }
    $cfg = include $path;
    return is_array($cfg) ? $cfg : [];
}

function valid_email(string $value): bool
{
    if ($value === '' || strlen($value) > EMAIL_MAX || preg_match('/[\r\n\0]/', $value)) {
        return false;
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
}

/** Leerer String, wenn die Konfiguration senden darf. Sonst ein Fehlercode. */
function block_reason(array $cfg): string
{
    if (empty($cfg['enabled'])) {
        return 'not_enabled';
    }
    if (empty($cfg['mailbox_confirmed'])) {
        return 'mailbox_unconfirmed';
    }
    if (!valid_email((string) ($cfg['recipient'] ?? ''))) {
        return 'recipient';
    }
    if (!valid_email((string) ($cfg['from'] ?? ''))) {
        return 'from';
    }
    $key = $cfg['hmac_key'] ?? '';
    if (!is_string($key) || strlen($key) < 32) {
        return 'hmac_key';
    }
    $dir = $cfg['storage_dir'] ?? '';
    if (!is_string($dir) || $dir === '' || !is_dir($dir) || !is_writable($dir)) {
        return 'storage';
    }
    $transport = (string) ($cfg['transport'] ?? '');
    if ($transport === 'fake') {
        if (getenv('MEGACLEAN_ALLOW_FAKE_TRANSPORT') !== '1') {
            return 'fake_forbidden';
        }
        return '';
    }
    if ($transport !== 'mail') {
        return 'transport';
    }
    if (getenv('MEGACLEAN_FORBID_REAL_MAIL') === '1') {
        return 'mail_forbidden';
    }
    return '';
}

function issue_token(array &$session, int $now): string
{
    $token = bin2hex(random_bytes(16));
    $session['csrf'] = $token;
    $session['issued_at'] = $now;
    return $token;
}

function host_from(array $server): string
{
    $origin = (string) ($server['HTTP_ORIGIN'] ?? '');
    $referer = (string) ($server['HTTP_REFERER'] ?? '');
    $raw = $origin !== '' ? $origin : $referer;
    if ($raw === '') {
        return '';
    }
    $host = parse_url($raw, PHP_URL_HOST);
    return is_string($host) ? strtolower($host) : '';
}

function host_allowed(array $cfg, array $server): bool
{
    $hosts = $cfg['allowed_hosts'] ?? [];
    if (!is_array($hosts) || $hosts === []) {
        return false;
    }
    $host = host_from($server);
    if ($host === '') {
        return false;
    }
    $allowed = array_map(static fn ($item) => strtolower((string) $item), $hosts);
    return in_array($host, $allowed, true);
}

function header_safe(string $value): bool
{
    return !preg_match('/[\r\n\0]/', $value);
}

function clean_text(string $value): string
{
    $value = str_replace("\0", '', $value);
    return trim($value);
}

function rate_purge(string $dir, int $now): void
{
    $files = glob(rtrim($dir, '/') . '/r-*.json');
    if (!is_array($files)) {
        return;
    }
    foreach ($files as $file) {
        $raw = file_get_contents($file);
        $data = json_decode(is_string($raw) ? $raw : '', true);
        $start = is_array($data) ? (int) ($data['start'] ?? 0) : 0;
        if ($start === 0 || ($now - $start) >= RATE_WINDOW) {
            unlink($file);
        }
    }
}

function rate_allow(array $cfg, string $ip, int $now): bool
{
    if ($ip === '') {
        return false;
    }
    $dir = rtrim((string) $cfg['storage_dir'], '/');
    rate_purge($dir, $now);
    $hash = hash_hmac('sha256', $ip, (string) $cfg['hmac_key']);
    $path = $dir . '/r-' . $hash . '.json';
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
 * @param array<string,mixed> $post
 * @param array<string,mixed> $server
 * @param array<string,mixed> $session
 * @return array{status:int,body:array{accepted:bool,delivery:?string,error:?string}}
 */
function handle(array $cfg, array $post, array $server, array &$session, int $now, ?Transport $transport = null): array
{
    $fail = static function (int $status, string $error): array {
        return ['status' => $status, 'body' => ['accepted' => false, 'delivery' => null, 'error' => $error]];
    };

    $reason = block_reason($cfg);
    if ($reason !== '') {
        return $fail(503, 'not_configured');
    }
    if (!host_allowed($cfg, $server)) {
        return $fail(403, 'origin');
    }
    if (!empty($post['website'])) {
        return $fail(400, 'rejected');
    }
    $csrf = (string) ($post['csrf'] ?? '');
    $known = (string) ($session['csrf'] ?? '');
    $issued = (int) ($session['issued_at'] ?? 0);
    if ($csrf === '' || $known === '' || !hash_equals($known, $csrf)) {
        return $fail(403, 'csrf');
    }
    $age = $now - $issued;
    if ($age < MIN_SECONDS) {
        return $fail(429, 'too_fast');
    }
    if ($age > MAX_AGE) {
        return $fail(403, 'expired');
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
    if ($phone !== '' && (strlen($phone) > PHONE_MAX || !header_safe($phone) || !preg_match('/^[0-9+\\-\\s()\\/]+$/', $phone))) {
        return $fail(400, 'phone');
    }
    if ($message === '' || strlen($message) > MESSAGE_MAX || !header_safe(str_replace(["\r", "\n"], '', $message))) {
        return $fail(400, 'message');
    }
    if (empty($post['datenschutz'])) {
        return $fail(400, 'privacy');
    }

    $ip = (string) ($server['REMOTE_ADDR'] ?? '');
    if (!rate_allow($cfg, $ip, $now)) {
        return $fail(429, 'rate');
    }

    $plain = "Name: {$name}\nE-Mail: {$email}\nTelefon: " . ($phone === '' ? '-' : $phone) . "\n\nNachricht:\n{$message}\n";
    $body = quoted_printable_encode($plain);
    $headers = "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: quoted-printable\r\n"
        . 'From: ' . $cfg['from'] . "\r\n"
        . 'Reply-To: ' . $email . "\r\n";
    $packet = [
        'to' => (string) $cfg['recipient'],
        'subject' => 'Mega Clean Website-Anfrage',
        'body' => $body,
        'headers' => $headers,
        'from' => (string) $cfg['from'],
    ];

    $used = $transport ?? make_transport($cfg);
    $ok = $used->accept($packet);
    if (!$ok) {
        return $fail(502, 'transport_rejected');
    }
    unset($session['csrf'], $session['issued_at']);
    return ['status' => 200, 'body' => ['accepted' => true, 'delivery' => 'unconfirmed', 'error' => null]];
}

function make_transport(array $cfg): Transport
{
    if (($cfg['transport'] ?? '') === 'fake') {
        if (getenv('MEGACLEAN_ALLOW_FAKE_TRANSPORT') !== '1') {
            throw new \RuntimeException('fake transport forbidden');
        }
        return new FakeTransport();
    }
    return new PhpMailTransport();
}
