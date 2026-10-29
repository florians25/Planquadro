<?php
/* Nimmt ntfy-Nachrichten entgegen und speichert sie für Tests. */
$file = sys_get_temp_dir() . '/opspilot-fake-ntfy.jsonl';
file_put_contents($file, json_encode(['topic' => trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'),
    'title' => $_SERVER['HTTP_TITLE'] ?? '', 'body' => file_get_contents('php://input')]) . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo '{"id":"x"}';
return true;
