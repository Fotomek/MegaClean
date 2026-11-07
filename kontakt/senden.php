<?php
namespace Megaclean\Kontakt;

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    $body = ['accepted' => false, 'delivery' => null, 'error' => 'method'];
    wants_json() ? json_out(405, $body) : html_out(405, public_text($body));
    exit;
}

$cfg = load_config(config_path());
if (block_reason($cfg) !== '') {
    $session = [];
    $result = handle($cfg, $_POST, $_SERVER, $session, time());
    wants_json() ? json_out($result['status'], $result['body']) : html_out($result['status'], public_text($result['body']));
    exit;
}

session_params();
session_start();
$result = handle($cfg, $_POST, $_SERVER, $_SESSION, time());
wants_json() ? json_out($result['status'], $result['body']) : html_out($result['status'], public_text($result['body']));
