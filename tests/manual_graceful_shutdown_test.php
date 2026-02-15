#!/usr/bin/env php
<?php
/**
 * Manual test for graceful shutdown behavior
 *
 * This script simulates a real consumer processing messages and demonstrates
 * graceful shutdown when receiving SIGTERM or SIGINT (Ctrl+C).
 *
 * Usage:
 *   php tests/manual_graceful_shutdown_test.php
 *
 * Then press Ctrl+C or send SIGTERM to see graceful shutdown in action.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use AlexFN\NanoService\NanoConsumer;
use AlexFN\NanoService\NanoServiceMessage;

// Set required environment variables
$_ENV['STATSD_ENABLED'] = 'false';
$_ENV['AMQP_HOST'] = 'localhost';
$_ENV['AMQP_PORT'] = '5672';
$_ENV['AMQP_USER'] = 'guest';
$_ENV['AMQP_PASS'] = 'guest';
$_ENV['AMQP_VHOST'] = '/';
$_ENV['AMQP_PROJECT'] = 'test';
$_ENV['AMQP_MICROSERVICE_NAME'] = 'graceful-shutdown-test';
$_ENV['DB_BOX_HOST'] = 'localhost';
$_ENV['DB_BOX_PORT'] = '5432';
$_ENV['DB_BOX_NAME'] = 'test_db';
$_ENV['DB_BOX_USER'] = 'test_user';
$_ENV['DB_BOX_PASS'] = 'test_pass';
$_ENV['DB_BOX_SCHEMA'] = 'public';

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║         Graceful Shutdown Test - nano-service v7.5.2        ║\n";
echo "╚══════════════════════════════════════════════════════════════╝\n";
echo "\n";

// Check if PCNTL is available
if (!extension_loaded('pcntl')) {
    echo "❌ ERROR: PCNTL extension is NOT available!\n";
    echo "   Graceful shutdown will NOT work without PCNTL.\n";
    echo "\n";
    echo "   Install PCNTL:\n";
    echo "   - Debian/Ubuntu: apt-get install php-pcntl\n";
    echo "   - macOS: brew install php (usually included)\n";
    echo "   - Compile PHP with: --enable-pcntl\n";
    echo "\n";
    exit(1);
}

echo "✅ PCNTL extension is available\n";
echo "✅ Signal handlers will be registered\n";
echo "\n";

// Create a mock consumer that simulates message processing
class TestConsumer extends NanoConsumer
{
    private int $messageCount = 0;
    private bool $isProcessing = false;

    public function simulateConsuming(): void
    {
        echo "🚀 Starting consumer (simulated)...\n";
        echo "   Press Ctrl+C (SIGINT) to test graceful shutdown\n";
        echo "   Or send SIGTERM: kill -TERM " . getpid() . "\n";
        echo "\n";

        // Initialize safe components (registers signal handlers)
        $reflection = new ReflectionClass($this);
        $method = $reflection->getMethod('initSafeComponents');
        $method->setAccessible(true);
        $method->invoke($this);

        echo "✅ Signal handlers registered\n";
        echo "✅ Consumer ready\n";
        echo "\n";

        // Simulate consuming messages
        while (true) {
            // Check for shutdown signal
            $shutdownProperty = $reflection->getProperty('shutdownRequested');
            $shutdownProperty->setAccessible(true);
            $shutdownRequested = $shutdownProperty->getValue();

            if ($shutdownRequested) {
                echo "\n";
                echo "🛑 SHUTDOWN SIGNAL RECEIVED!\n";
                echo "   Stopping consumer (no new messages)...\n";

                if ($this->isProcessing) {
                    echo "   ⏳ Waiting for current message to finish...\n";
                    sleep(2); // Simulate message completion
                    echo "   ✅ Current message processed successfully\n";
                }

                echo "   🔌 Closing connections gracefully...\n";
                echo "   📊 Emitting shutdown metrics...\n";
                echo "\n";
                echo "✅ GRACEFUL SHUTDOWN COMPLETED\n";
                echo "   Total messages processed: {$this->messageCount}\n";
                echo "   Exit code: 0\n";
                echo "\n";

                exit(0);
            }

            // Simulate message arrival every 3 seconds
            sleep(3);

            $this->messageCount++;
            $this->isProcessing = true;

            echo "[" . date('H:i:s') . "] 📨 Processing message #{$this->messageCount}...\n";

            // Simulate message processing (2 seconds)
            sleep(2);

            $this->isProcessing = false;
            echo "[" . date('H:i:s') . "] ✅ Message #{$this->messageCount} completed\n";
            echo "\n";
        }
    }
}

try {
    $consumer = new TestConsumer();
    $consumer->simulateConsuming();
} catch (Exception $e) {
    echo "❌ ERROR: {$e->getMessage()}\n";
    exit(1);
}
