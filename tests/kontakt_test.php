<?php
namespace Megaclean\Kontakt;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/kontakt/handler.php';

$fail = 0;
function check(string $name, bool $ok): void
{
    global $fail;
    if ($ok) {
        echo "ok  $name\n";
        return;
    }
    echo "FAIL $name\n";
    $fail++;
}

$dir = sys_get_temp_dir() . '/mc-kontakt-unit-' . getmypid();
mkdir($dir, 0700, true);
putenv('MEGACLEAN_RATE_DIR=' . $dir);

$now = 1_700_000_010;
$base = [
    'ts' => (string) ($now - 10),
    'name' => 'Test Person',
    'email' => 'test@example.com',
    'phone' => '',
    'message' => 'Nur ein Test im lokalen Lauf.',
    'website' => '',
];
$server = ['REMOTE_ADDR' => '203.0.113.5'];
$caught = [];
$mailer = static function (array $packet) use (&$caught): bool {
    $caught[] = $packet;
    return true;
};

$caught = [];
$r = handle($base, $server, $now, $mailer);
check('valid accepted', $r['status'] === 200 && $r['body']['ok'] === true && $r['mailed'] === true);
check('one mail', count($caught) === 1);
check('recipient', $caught[0]['to'] === 'info@megacleanberlin.de');
check('from', $caught[0]['from'] === 'noreply@megacleanberlin.de');
check('reply-to', str_contains($caught[0]['headers'], 'Reply-To: test@example.com'));
check('from header', str_contains($caught[0]['headers'], 'From: Mega Clean Berlin <noreply@megacleanberlin.de>'));
check('no request in json', !str_contains(json_encode($r['body']), 'Nur ein Test'));
$decoded = quoted_printable_decode($caught[0]['body']);
check('body has message', str_contains($decoded, 'Nur ein Test im lokalen Lauf.') && str_contains($decoded, 'Test Person'));

$caught = [];
$honey = $base;
$honey['website'] = 'http://spam.example';
$r = handle($honey, $server, $now, $mailer);
check('honeypot silent ok', $r['status'] === 200 && $r['body']['ok'] === true && $r['mailed'] === false);
check('honeypot no mail', $caught === []);

$caught = [];
$fast = $base;
$fast['ts'] = (string) $now;
$r = handle($fast, $server, $now, $mailer);
check('too fast', $r['status'] === 429 && $r['body']['error'] === 'too_fast' && $r['mailed'] === false);
check('too fast no mail', $caught === []);

$caught = [];
$bad = $base;
$bad['email'] = 'kein-at-zeichen';
$r = handle($bad, $server, $now, $mailer);
check('invalid email', $r['status'] === 400 && $r['body']['error'] === 'email' && $r['mailed'] === false);

$caught = [];
$inj = $base;
$inj['email'] = "test@example.com\nBcc: x@example.com";
$r = handle($inj, $server, $now, $mailer);
check('header injection email', $r['status'] === 400 && $r['body']['error'] === 'email' && $r['mailed'] === false);

$caught = [];
$inj2 = $base;
$inj2['name'] = "Test\r\nBcc: x@example.com";
$r = handle($inj2, $server, $now, $mailer);
check('header injection name', $r['status'] === 400 && $r['body']['error'] === 'name' && $r['mailed'] === false);

$caught = [];
$umlaut = $base;
$umlaut['name'] = 'Jörg Müller';
$umlaut['email'] = 'joerg@example.com';
$umlaut['message'] = "Tür und Straße\nZweite Zeile";
$umlaut['phone'] = '+49 30 123456';
$r = handle($umlaut, ['REMOTE_ADDR' => '203.0.113.8'], $now, $mailer);
check('umlaut accepted', $r['mailed'] === true);
$decoded = quoted_printable_decode($caught[0]['body']);
check('umlaut body', str_contains($decoded, 'Jörg Müller') && str_contains($decoded, 'Tür und Straße'));
check('utf8 header', str_contains($caught[0]['headers'], 'charset=UTF-8') && str_contains($caught[0]['headers'], 'quoted-printable'));
check('subject encoded', str_starts_with($caught[0]['subject'], '=?UTF-8?B?'));

$okCount = 0;
$rateCaught = 0;
$rateMailer = static function (array $packet) use (&$rateCaught): bool {
    $rateCaught++;
    return true;
};
for ($i = 0; $i < 6; $i++) {
    $one = $base;
    $one['email'] = "rate{$i}@example.com";
    $r = handle($one, ['REMOTE_ADDR' => '203.0.113.9'], $now + $i, $rateMailer);
    if (!empty($r['body']['ok']) && $r['mailed']) {
        $okCount++;
    }
    $last = $r;
}
check('rate limit allows five', $okCount === 5 && $rateCaught === 5);
check('sixth is rate', $last['body']['error'] === 'rate' && $last['status'] === 429);

$hash = hash_hmac('sha256', '203.0.113.9', HMAC_KEY);
$rateFile = $dir . '/r-' . $hash;
check('rate file exists', is_file($rateFile));
$rateRaw = file_get_contents($rateFile);
check('rate file has no raw ip', is_string($rateRaw) && !str_contains($rateRaw, '203.0.113.9'));
check('rate filename has no raw ip', !str_contains($rateFile, '203.0.113.9'));

$failing = static fn (array $packet): bool => false;
$r = handle($base, ['REMOTE_ADDR' => '203.0.113.10'], $now, $failing);
check('mail failure', $r['status'] === 502 && $r['body']['error'] === 'mail' && $r['mailed'] === false);

echo $fail === 0 ? "ALL OK\n" : "FAILURES $fail\n";
exit($fail === 0 ? 0 : 1);
