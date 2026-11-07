<?php
namespace Megaclean\Kontakt;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

putenv('MEGACLEAN_FORBID_REAL_MAIL=1');
putenv('MEGACLEAN_ALLOW_FAKE_TRANSPORT=1');

function mail($to, $subject, $message, $headers = '', $params = '')
{
    $GLOBALS['__mc_mail_called'] = true;
    throw new \RuntimeException('mail() blocked in test');
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

function base_cfg(string $dir): array
{
    return [
        'enabled' => true,
        'mailbox_confirmed' => true,
        'recipient' => 'info@megacleanberlin.de',
        'from' => 'website@example.invalid',
        'allowed_hosts' => ['127.0.0.1'],
        'storage_dir' => $dir,
        'hmac_key' => str_repeat('k', 32),
        'transport' => 'fake',
    ];
}

function server(): array
{
    return ['HTTP_ORIGIN' => 'http://127.0.0.1', 'REMOTE_ADDR' => '203.0.113.5'];
}

$dir = sys_get_temp_dir() . '/mc-kontakt-test-' . getmypid();
mkdir($dir, 0700, true);

check('missing config blocked', block_reason([]) === 'not_enabled');
$off = base_cfg($dir);
$off['enabled'] = false;
check('disabled blocked', block_reason($off) === 'not_enabled');
$unconfirmed = base_cfg($dir);
$unconfirmed['mailbox_confirmed'] = false;
check('unconfirmed mailbox blocked', block_reason($unconfirmed) === 'mailbox_unconfirmed');

$session = [];
$now = 1_700_000_000;
$token = issue_token($session, $now - 10);
$fake = new FakeTransport();
$post = [
    'csrf' => $token,
    'name' => 'Test Person',
    'email' => 'test@example.com',
    'phone' => '',
    'message' => 'Nur ein Test im lokalen Lauf.',
    'datenschutz' => '1',
    'website' => '',
];
$result = handle(base_cfg($dir), $post, server(), $session, $now, $fake);
check('fake accept', $result['status'] === 200 && $result['body']['accepted'] === true && $result['body']['delivery'] === 'unconfirmed');
check('mail() not called', empty($GLOBALS['__mc_mail_called']));
check('recipient is business address', $fake->accepted[0]['to'] === 'info@megacleanberlin.de');
check('from is fixed', $fake->accepted[0]['from'] === 'website@example.invalid');
check('reply-to is visitor', str_contains($fake->accepted[0]['headers'], 'Reply-To: test@example.com'));
check('response has no message', !str_contains(json_encode($result['body']), 'Nur ein Test'));

$session = [];
$token = issue_token($session, $now - 10);
$bad = $post;
$bad['csrf'] = $token;
$bad['email'] = "test@example.com\nBcc: x@example.com";
$r = handle(base_cfg($dir), $bad, server(), $session, $now, new FakeTransport());
check('header injection rejected', $r['status'] === 400 && $r['body']['accepted'] === false);

$session = [];
$token = issue_token($session, $now - 1);
$fast = $post;
$fast['csrf'] = $token;
$r = handle(base_cfg($dir), $fast, server(), $session, $now, new FakeTransport());
check('too fast', $r['body']['error'] === 'too_fast');

$session = [];
$token = issue_token($session, $now - 10);
$honey = $post;
$honey['csrf'] = $token;
$honey['website'] = 'http://spam.example';
$r = handle(base_cfg($dir), $honey, server(), $session, $now, new FakeTransport());
check('honeypot', $r['body']['error'] === 'rejected');

$session = [];
$token = issue_token($session, $now - 10);
$origin = $post;
$origin['csrf'] = $token;
$srv = server();
$srv['HTTP_ORIGIN'] = 'https://evil.example';
$r = handle(base_cfg($dir), $origin, $srv, $session, $now, new FakeTransport());
check('origin', $r['body']['error'] === 'origin');

$session = [];
$r = handle(base_cfg($dir), $post, server(), $session, $now, new FakeTransport());
check('missing csrf', $r['body']['error'] === 'csrf');

$cfg = base_cfg($dir);
$ipServer = server();
$ipServer['REMOTE_ADDR'] = '203.0.113.9';
$okCount = 0;
for ($i = 0; $i < 6; $i++) {
    $session = [];
    $token = issue_token($session, $now - 10);
    $one = $post;
    $one['csrf'] = $token;
    $one['email'] = "rate{$i}@example.com";
    $r = handle($cfg, $one, $ipServer, $session, $now + $i, new FakeTransport());
    if ($r['body']['accepted']) {
        $okCount++;
    }
}
check('rate limit allows five', $okCount === 5);

$transport = new PhpMailTransport();
$threw = false;
try {
    $transport->accept([
        'to' => 'info@megacleanberlin.de',
        'subject' => 'x',
        'body' => 'x',
        'headers' => 'From: website@example.invalid',
        'from' => 'website@example.invalid',
    ]);
} catch (\RuntimeException $e) {
    $threw = $e->getMessage() === 'real mail forbidden';
}
check('real mail transport refused', $threw && empty($GLOBALS['__mc_mail_called']));

$empty = [];
$r = handle([], $post, server(), $empty, $now, new FakeTransport());
check('no config no accept', $r['status'] === 503 && $r['body']['accepted'] === false);

$stale = $dir . '/r-stale.json';
file_put_contents($stale, json_encode(['start' => $now - RATE_WINDOW - 5, 'n' => 1]));
$freshHash = hash_hmac('sha256', '203.0.113.50', str_repeat('k', 32));
$fresh = $dir . '/r-' . $freshHash . '.json';
file_put_contents($fresh, json_encode(['start' => $now - 10, 'n' => 1]));
check('stale rate file remains without a request', is_file($stale));
$session = [];
$token = issue_token($session, $now - 10);
$purgePost = $post;
$purgePost['csrf'] = $token;
$purgePost['email'] = 'purge@example.com';
$purgeServer = server();
$purgeServer['REMOTE_ADDR'] = '203.0.113.77';
handle(base_cfg($dir), $purgePost, $purgeServer, $session, $now, new FakeTransport());
check('stale rate file removed on next request', !is_file($stale));
check('fresh rate file kept', is_file($fresh));

$session = [];
$token = issue_token($session, $now - 10);
$firstPost = $post;
$firstPost['csrf'] = $token;
$firstPost['email'] = 'erste@example.com';
$chain = new FakeTransport();
$first = handle(base_cfg($dir), $firstPost, server(), $session, $now, $chain);
check('first accept consumes csrf', $first['body']['accepted'] === true && !isset($session['csrf']));
$again = $firstPost;
$again['email'] = 'erneut@example.com';
$again['message'] = 'Zweite Anfrage mit verbrauchtem Token.';
$reused = handle(base_cfg($dir), $again, server(), $session, $now + 30, $chain);
check('spent csrf rejected', $reused['status'] === 403 && $reused['body']['error'] === 'csrf');
$token2 = issue_token($session, $now + 20);
$secondPost = $post;
$secondPost['csrf'] = $token2;
$secondPost['email'] = 'zweite@example.com';
$secondPost['message'] = 'Zweite Anfrage mit neuem Token.';
$second = handle(base_cfg($dir), $secondPost, server(), $session, $now + 30, $chain);
check('second accept without new session', $second['body']['accepted'] === true && $second['body']['delivery'] === 'unconfirmed');
check('two fake accepts and no mail()', count($chain->accepted) === 2 && empty($GLOBALS['__mc_mail_called']));

$session = [];
$token = issue_token($session, $now - 10);
$umlaut = $post;
$umlaut['csrf'] = $token;
$umlaut['name'] = 'Jörg Müller';
$umlaut['message'] = 'Tür und Straße';
$umlaut['email'] = 'joerg@example.com';
$utf = new FakeTransport();
$ur = handle(base_cfg($dir), $umlaut, server(), $session, $now, $utf);
$decoded = quoted_printable_decode($utf->accepted[0]['body']);
check('umlaut accepted', $ur['body']['accepted'] === true);
check('umlaut body', str_contains($decoded, 'Jörg Müller') && str_contains($decoded, 'Tür und Straße'));
check('utf8 quoted printable', str_contains($utf->accepted[0]['headers'], 'charset=UTF-8') && str_contains($utf->accepted[0]['headers'], 'quoted-printable'));

echo $fail === 0 ? "ALL OK\n" : "FAILURES $fail\n";
exit($fail === 0 ? 0 : 1);
