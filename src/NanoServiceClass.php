<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Clients\StatsDClient\StatsDClient;
use AlexFN\NanoService\Traits\Environment;
use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;

/**
 * Base class for nano-service with connection health tracking
 *
 * Manages RabbitMQ connections and channels with metrics instrumentation
 * for monitoring connection/channel health.
 *
 * @package AlexFN\NanoService
 */
class NanoServiceClass
{
    use Environment;

    const PROJECT = 'AMQP_PROJECT';

    const HOST = 'AMQP_HOST';

    const PORT = 'AMQP_PORT';

    const USER = 'AMQP_USER';

    const PASS = 'AMQP_PASS';

    const VHOST = 'AMQP_VHOST';

    const MICROSERVICE_NAME = 'AMQP_MICROSERVICE_NAME';

    // Static shared connection pool - one connection per worker process
    protected static ?AMQPStreamConnection $sharedConnection = null;
    protected static $sharedChannel = null;

    //protected AMQPStreamConnection $connection;
    protected $connection;

    //protected AbstractChannel $channel;
    protected $channel;

    // Outage circuit breaker state
    private bool $outageMode = false;

    /** @var callable|null Called when entering outage mode: fn(int $sleepSeconds): void */
    private $onOutageEnter = null;

    /** @var callable|null Called when exiting outage mode: fn(): void */
    private $onOutageExit = null;

    //protected string $exchange = 'default';
    protected $exchange = 'bus';

    //protected string $queue = 'default';
    protected $queue = 'default';

    // StatsD client for metrics (lazy-loaded)
    protected ?StatsDClient $statsD = null;

    /**
     * @throws Exception
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;
        $this->validateRequiredEnv();
    }

    /**
     * Validate required environment variables at startup
     *
     * Fail-fast: throws immediately if critical env vars are missing.
     * Called once in constructor — no need to re-check in subclasses.
     *
     * @throws \RuntimeException If required environment variables are missing
     */
    private function validateRequiredEnv(): void
    {
        if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
            throw new \RuntimeException('Missing required environment variable: AMQP_MICROSERVICE_NAME');
        }
    }

    /**
     * Initialize StatsD client (lazy initialization)
     *
     * @return void
     */
    protected function initStatsD(): void
    {
        if (!$this->statsD) {
            $this->statsD = new StatsDClient();
        }
    }

    protected function exchange(
        string $exchange,
        string $exchangeType = AMQPExchangeType::TOPIC,
        $arguments = []
    ): NanoServiceClass {
        $this->exchange = $this->getNamespace($exchange);

        return $this->createExchange($this->exchange, $exchangeType, $arguments);
    }

    protected function createExchange(
        string $exchange,
        string $exchangeType = AMQPExchangeType::TOPIC,
        $arguments = [],
        bool $passive = false,
        bool $durable = true,
        bool $auto_delete = false,
        bool $internal = false,
        bool $nowait = false
    ): NanoServiceClass {
        $this->getChannel()->exchange_declare($exchange, $exchangeType, $passive, $durable, $auto_delete, $internal, $nowait, $arguments);

        return $this;
    }

    protected function queue(string $queue, $arguments = []): NanoServiceClass
    {
        $this->queue = $this->getNamespace($queue);

        return $this->createQueue($this->queue, $arguments);
    }

    protected function createQueue(string $queue, $arguments = [], $passive = false, $durable = true, $exclusive = false, $auto_delete = false, $nowait = false): NanoServiceClass
    {
        $this->getChannel()->queue_declare($queue, $passive, $durable, $exclusive, $auto_delete, $nowait, $arguments);

        return $this;
    }

    protected function declare($queue): NanoServiceClass
    {
        $queue = $this->getNamespace($queue);

        $this->exchange = $queue;
        $this->queue = $queue;

        $this->exchange($queue);
        $this->queue($queue);

        return $this;
    }

    public function getProject(): string
    {
        return $this->getEnv(self::PROJECT);
    }

    public function getNamespace(string $path): string
    {
        return "{$this->getProject()}.$path";
    }

    /**
     * Get RabbitMQ channel with health metrics tracking
     *
     * Tracks:
     * - rmq_channel_total: Channel open events
     * - rmq_channel_active: Active channels gauge
     * - rmq_channel_errors_total: Channel errors
     *
     * @return \PhpAmqpLib\Channel\AbstractChannel|mixed
     */
    public function getChannel()
    {
        $this->initStatsD();

        // Try to use shared channel first (connection pooling)
        if (self::$sharedChannel && self::$sharedChannel->is_open()) {
            return self::$sharedChannel;
        }

        // Create new channel if needed and store in shared pool
        if (! $this->channel || !$this->channel->is_open()) {
            try {
                $this->channel = $this->getConnection()->channel();

                // Store in shared pool for reuse across all instances in this worker process
                // This prevents channel leaks by ensuring all instances share the same channel
                self::$sharedChannel = $this->channel;

                // Track channel opened
                if ($this->statsD && $this->statsD->isEnabled()) {
                    $this->statsD->increment('rmq_channel_total', 1, 1, ['status' => 'success']);
                    $this->statsD->gauge('rmq_channel_active', 1);
                }

            } catch (\Exception $e) {
                // Track channel error
                if ($this->statsD && $this->statsD->isEnabled()) {
                    $this->statsD->increment('rmq_channel_errors_total', 1, 1, [
                        'error_type' => 'channel_failed'
                    ]);
                }
                throw $e;
            }
        }

        return $this->channel;
    }

    /**
     * Get RabbitMQ connection with health metrics tracking
     *
     * Tracks:
     * - rmq_connection_total: Connection open events
     * - rmq_connection_active: Active connections gauge
     * - rmq_connection_errors_total: Connection errors
     *
     * @return AMQPStreamConnection
     */
    public function getConnection(): AMQPStreamConnection
    {
        $this->initStatsD();

        // Try to use shared connection first (connection pooling)
        if (self::$sharedConnection && self::$sharedConnection->isConnected()) {
            return self::$sharedConnection;
        }

        // Fallback to instance connection if shared is not available
        if (! $this->connection) {
            // Note: Creating a new AMQPStreamConnection forces fresh DNS resolution
            // because php-amqplib resolves hostname on connect() via fsockopen().
            // This handles stale DNS after Cilium proxy restarts.
            try {
                $this->connection = new AMQPStreamConnection(
                    $this->getEnv(self::HOST),
                    $this->getEnv(self::PORT),
                    $this->getEnv(self::USER),
                    $this->getEnv(self::PASS),
                    $this->getEnv(self::VHOST),
                    false,  // insist
                    'AMQPLAIN',  // login_method
                    null,  // login_response
                    'en_US',  // locale
                    10.0,  // connection_timeout
                    10.0,  // read_write_timeout
                    null,  // context
                    true,  // keepalive
                    180    // heartbeat (match RabbitMQ server config)
                );

                // Store in shared pool for reuse by this worker process
                self::$sharedConnection = $this->connection;

                // Track connection opened
                if ($this->statsD && $this->statsD->isEnabled()) {
                    $this->statsD->increment('rmq_connection_total', 1, 1, ['status' => 'success']);
                    $this->statsD->gauge('rmq_connection_active', 1);
                }

            } catch (\Exception $e) {
                // Track connection error
                if ($this->statsD && $this->statsD->isEnabled()) {
                    $this->statsD->increment('rmq_connection_errors_total', 1, 1, [
                        'error_type' => 'connection_failed'
                    ]);
                }
                throw $e;
            }
        }

        return $this->connection;
    }

    /**
     * Check if RabbitMQ connection is healthy and send heartbeat
     *
     * checkHeartBeat() does two things:
     * 1. Throws AMQPHeartbeatMissedException if idle > 2x heartbeat interval (connection stale)
     * 2. Sends heartbeat to server if idle > half heartbeat interval (keeps connection alive)
     *
     * If unhealthy, resets connection so next getConnection() creates a fresh one.
     *
     * @return bool true if connection is healthy
     */
    public function isConnectionHealthy(): bool
    {
        try {
            $connection = $this->getConnection();

            if (!$connection->isConnected()) {
                $this->reset();
                return false;
            }

            $connection->checkHeartBeat();

            return true;
        } catch (\PhpAmqpLib\Exception\AMQPHeartbeatMissedException $e) {
            $this->reset();
            return false;
        } catch (\Exception) {
            $this->reset();
            return false;
        }
    }

    /**
     * Set callbacks for outage mode events
     *
     * @param callable|null $onEnter Called when entering outage: fn(int $sleepSeconds): void
     * @param callable|null $onExit  Called when connection restored: fn(): void
     */
    public function setOutageCallbacks(?callable $onEnter, ?callable $onExit): void
    {
        $this->onOutageEnter = $onEnter;
        $this->onOutageExit = $onExit;
    }

    /**
     * Check connection health with outage circuit breaker
     *
     * Call this in your worker loop before processing jobs.
     * If unhealthy: enters outage mode, sleeps, returns false.
     * If healthy after outage: exits outage mode, returns true.
     *
     * @param int $outageSleepSeconds Seconds to sleep during outage
     * @return bool true if connection is healthy and ready to process
     */
    public function ensureConnectionOrSleep(int $outageSleepSeconds): bool
    {
        if (!$this->isConnectionHealthy()) {
            if (!$this->outageMode) {
                $this->outageMode = true;
                if ($this->onOutageEnter) {
                    ($this->onOutageEnter)($outageSleepSeconds);
                }
            }
            sleep($outageSleepSeconds);
            return false;
        }

        if ($this->outageMode) {
            $this->outageMode = false;
            if ($this->onOutageExit) {
                ($this->onOutageExit)();
            }
        }

        return true;
    }

    public function isInOutage(): bool
    {
        return $this->outageMode;
    }

    public function reset(): void
    {
        // Close channel explicitly before nulling
        if (self::$sharedChannel && method_exists(self::$sharedChannel, 'is_open') && self::$sharedChannel->is_open()) {
            try {
                self::$sharedChannel->close();
            } catch (\Throwable $e) {
                // Suppress errors during shutdown - connection might already be dead
            }
        }

        // Close connection explicitly before nulling
        if (self::$sharedConnection && method_exists(self::$sharedConnection, 'isConnected') && self::$sharedConnection->isConnected()) {
            try {
                self::$sharedConnection->close();
            } catch (\Throwable $e) {
                // Suppress errors during shutdown - connection might already be dead
            }
        }

        $this->channel = null;
        $this->connection = null;
        self::$sharedChannel = null;
        self::$sharedConnection = null;
    }

    /**
     * Destructor to clean up instance channels that differ from shared channel
     * This is a safety net to prevent channel leaks in edge cases
     */
    public function __destruct()
    {
        // Update gauge on cleanup (only if this instance created a non-shared channel)
        if ($this->statsD
            && $this->statsD->isEnabled()
            && $this->channel
            && $this->channel !== self::$sharedChannel
            && method_exists($this->channel, 'is_open')
            && $this->channel->is_open()
        ) {
            $this->statsD->gauge('rmq_channel_active', 0);
        }

        // Only close instance channel if it's different from the shared one
        if ($this->channel
            && $this->channel !== self::$sharedChannel
            && method_exists($this->channel, 'is_open')
            && $this->channel->is_open()
        ) {
            try {
                $this->channel->close();
            } catch (\Throwable $e) {
                // Suppress errors during shutdown
                // Logging here might fail if logger is already destroyed
            }
        }
    }
}
