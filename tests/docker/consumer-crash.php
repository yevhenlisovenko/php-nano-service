#!/usr/bin/env php
<?php
// Slow handler for the crash-mid-message scenario: the runner kills the pod that logs "handler_started".
require_once __DIR__ . '/../../vendor/autoload.php';

use AlexFN\NanoService\NanoConsumer;
use AlexFN\NanoService\NanoServiceMessage;

$sleep = (int) getenv('HANDLER_SLEEP_SECONDS');
if ($sleep <= 0) {
    fwrite(STDERR, "HANDLER_SLEEP_SECONDS is required\n");
    exit(1);
}

$consumer = new NanoConsumer();
$consumer->events('user.created')->tries(3)->backoff(1);
$consumer->consume(function (NanoServiceMessage $message) use ($sleep) {
    echo json_encode(['message' => 'handler_started', 'message_id' => $message->getId(), 'pod' => getenv('POD_NAME')]) . "\n";
    sleep($sleep);
    echo json_encode(['message' => 'handler_finished', 'message_id' => $message->getId(), 'pod' => getenv('POD_NAME')]) . "\n";
});
