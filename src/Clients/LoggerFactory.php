<?php

declare(strict_types=1);

namespace AlexFN\NanoService\Clients;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LoggerFactory - Creates Loki-compatible JSON loggers
 *
 * Provides structured JSON logging for Loki/Grafana compatibility
 * across all nano-services.
 *
 * Compatible with both Monolog v2 and v3:
 * - Monolog v2: Uses Logger constants (Logger::DEBUG = 100, etc.)
 * - Monolog v3: Uses Level enum (Level::Debug, etc.)
 */
final class LoggerFactory
{
    /** @var \Monolog\Level|int Log level (Level enum in v3, int constant in v2) */
    private $level;

    /** @var HandlerInterface[] */
    private array $handlers = [];

    private ?HandlerInterface $test = null;

    public function __construct(array $settings = [])
    {
        $this->level = $settings['level'] ?? $this->getDefaultLevel();
        $this->test = $settings['test'] ?? null;
    }

    public static function getInstance(array $settings = []): LoggerInterface
    {
        return (new self($settings))
            ->addJsonConsoleHandler()
            ->createLogger('php-nano-service');
    }

    /**
     * Get default log level compatible with both Monolog v2 and v3
     *
     * @return \Monolog\Level|int
     */
    private function getDefaultLevel()
    {
        // Monolog v3: Use Level enum
        if (class_exists('Monolog\Level')) {
            return \Monolog\Level::Debug;
        }

        // Monolog v2: Use Logger constant
        return Logger::DEBUG;
    }

    /**
     * Create a logger with all configured handlers
     */
    public function createLogger(?string $name = null): LoggerInterface
    {
        if ($this->test) {
            $this->handlers = [$this->test];
        }

        $logger = new Logger($name ?: self::generateId());

        // Add PSR-3 message processor for placeholder replacement
        $logger->pushProcessor(new PsrLogMessageProcessor());

        foreach ($this->handlers as $handler) {
            $logger->pushHandler($handler);
        }

        $this->handlers = [];

        return $logger;
    }

    /**
     * Add a custom handler
     */
    public function addHandler(HandlerInterface $handler): self
    {
        $this->handlers[] = $handler;

        return $this;
    }

    /**
     * Add JSON console handler for Loki-compatible output
     *
     * Output format is single-line JSON per log entry:
     * {"message":"...","context":{...},"level":200,"level_name":"INFO","channel":"...","datetime":"..."}
     *
     * @param \Monolog\Level|int|null $level Log level (Monolog v3 Level enum or v2 int constant)
     */
    public function addJsonConsoleHandler($level = null): self
    {
        $handler = new StreamHandler('php://stdout', $level ?? $this->level);

        $formatter = new JsonFormatter();
        $formatter->includeStacktraces(true);
        $handler->setFormatter($formatter);

        $this->addHandler($handler);

        return $this;
    }

    /**
     * Add plain text console handler (for local development)
     *
     * @param \Monolog\Level|int|null $level Log level (Monolog v3 Level enum or v2 int constant)
     */
    public function addConsoleHandler($level = null): self
    {
        $handler = new StreamHandler('php://stdout', $level ?? $this->level);

        $this->addHandler($handler);

        return $this;
    }

    private static function generateId(): string
    {
        if (class_exists(\Symfony\Component\Uid\Uuid::class)) {
            return \Symfony\Component\Uid\Uuid::v7()->toRfc4122();
        }

        if (class_exists(\Ramsey\Uuid\Uuid::class)) {
            return \Ramsey\Uuid\Uuid::uuid7()->toString();
        }

        return bin2hex(random_bytes(16));
    }
}
