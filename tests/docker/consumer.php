#!/usr/bin/env php
<?php
/**
 * Test consumer for Docker integration testing
 *
 * This consumer demonstrates graceful shutdown when receiving SIGTERM.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use AlexFN\NanoService\NanoConsumer;
use AlexFN\NanoService\NanoServiceMessage;

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         Docker Test Consumer - nano-service v7.5.2          ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Verify PCNTL is available
if (!extension_loaded('pcntl')) {
    echo "❌ ERROR: PCNTL extension is NOT available!\n";
    echo "   Graceful shutdown will NOT work.\n";
    exit(1);
}

echo "✅ PCNTL extension is available\n";
echo "✅ Process PID: " . getmypid() . "\n";
echo "\n";

try {
    $consumer = new NanoConsumer();
    $consumer->events('user.created');

    echo "✅ Consumer created\n";
    echo "✅ Will listen for messages on: user.created\n";
    echo "   Press Ctrl+C or send SIGTERM to test graceful shutdown\n";
    echo "\n";

    // consume() will handle initialization internally with circuit breaker
    $consumer->consume(function (NanoServiceMessage $message) {
        $data = $message->getData();
        echo "[" . date('H:i:s') . "] 📨 Received message\n";
        echo "   User ID: " . ($data['user_id'] ?? 'unknown') . "\n";
        echo "   Message ID: " . $message->getId() . "\n";
        echo "\n";

        // Simulate processing time
        echo "   ⏳ Processing message (5 seconds)...\n";
        sleep(5);

        echo "   ✅ Message processed successfully\n";
        echo "\n";
    });

} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    echo "   Trace:\n{$e->getTraceAsString()}\n";
    exit(1);
}

echo "\n";
echo "✅ Consumer exited cleanly\n";
exit(0);
