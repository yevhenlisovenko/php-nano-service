<?php

declare(strict_types=1);

namespace AlexFN\NanoService\Clients;

use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LoggerFactory - Creates Loki-compatible JSON loggers
 *
 * Provides structured JSON logging for Loki/Grafana compatibility
 * across all nano-services.
 */
final class LoggerFactory
{
    private Level $level;

    /** @var HandlerInterface[] */
    private array $handlers = [];

    private ?HandlerInterface $test = null;

    public function __construct(array $settings = [])
    {
        $this->level = $settings['level'] ?? Level::Debug;
        $this->test = $settings['test'] ?? null;
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
     */
    public function addJsonConsoleHandler(?Level $level = null): self
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
     */
    public function addConsoleHandler(?Level $level = null): self
    {
        $handler = new StreamHandler('php://stdout', $level ?? $this->level);

        $this->addHandler($handler);

        return $this;
    }

    private static function generateId(): string
    {
        if (class_exists(\Symfony\Component\Uid\Uuid::class)) {
            return \Symfony\Component\Uid\Uuid::v4()->toRfc4122();
        }

        if (class_exists(\Ramsey\Uuid\Uuid::class)) {
            return \Ramsey\Uuid\Uuid::uuid4()->toString();
        }

        return bin2hex(random_bytes(16));
    }
}
