<?php

// Router for PHP's built-in server (php -S): a chat-completions endpoint
// that echoes the text it was asked to translate with a "!" appended. A
// request with an X-Delay header (seconds) is answered that much later.

$request = json_decode((string) file_get_contents('php://input'), true);
$delay = (float) ($_SERVER['HTTP_X_DELAY'] ?? 0);
if ($delay > 0) {
    usleep((int) ($delay * 1_000_000));
}

header('Content-Type: application/json');
$text = $request['messages'][count($request['messages'] ?? []) - 1]['content'] ?? '';
echo json_encode(['choices' => [['message' => ['content' => $text.'!'], 'finish_reason' => 'stop']]]);
