<?php
namespace Megaclean\Kontakt;

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    json_out(405, ['error' => 'method']);
    exit;
}

$cfg = load_config(config_path());
if (block_reason($cfg) !== '') {
    json_out(503, ['error' => 'not_configured']);
    exit;
}

session_params();
session_start();
$token = issue_token($_SESSION, time());
json_out(200, ['csrf' => $token]);
