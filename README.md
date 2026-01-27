# Nano-Service

Event-driven microservices package for PHP using RabbitMQ.

## Features

- Publish and consume messages through RabbitMQ
- Event-driven architecture with message queues
- Comprehensive StatsD metrics for observability (v6.0+)
- Connection pooling and health monitoring
- Message signing and verification

## Installation

```bash
composer require yevhenlisovenko/nano-service
```

## Quick Start

### Publisher

```php
use AlexFN\NanoService\NanoPublisher;
use AlexFN\NanoService\NanoServiceMessage;

$publisher = new NanoPublisher();
$message = new NanoServiceMessage();
$message->setPayload(['user_id' => 123, 'action' => 'created']);

$publisher->setMessage($message)->publish('user.created');
```

### Consumer

```php
use AlexFN\NanoService\NanoConsumer;

$consumer = new NanoConsumer();
$consumer
    ->events('user.created', 'user.updated')
    ->tries(3)
    ->consume(function ($message) {
        echo "Processing: " . $message->getEventName() . "\n";
    });
```

## Configuration

### RabbitMQ (Required)

```bash
export AMQP_HOST="rabbitmq.internal"
export AMQP_PORT="5672"
export AMQP_USER="user"
export AMQP_PASS="password"
export AMQP_VHOST="/"
export AMQP_PROJECT="myproject"
export AMQP_MICROSERVICE_NAME="myservice"
```

### StatsD Metrics (Optional)

```bash
export STATSD_ENABLED="true"
export STATSD_HOST="10.192.0.15"
export STATSD_PORT="8125"
export STATSD_NAMESPACE="myservice"
export STATSD_SAMPLE_OK="0.1"
```

## Metrics (v6.0+)

Automatic metrics collection for:
- Publisher: rate, latency, errors, payload sizes
- Consumer: processing rate, retries, DLX events
- Connection health: status, errors, lifecycle

**No code changes required** - metrics are collected automatically when enabled.

## Documentation

| Document | Description |
|----------|-------------|
| [docs/METRICS.md](docs/METRICS.md) | Complete metrics reference, helper classes |
| [docs/CONFIGURATION.md](docs/CONFIGURATION.md) | Configuration options and examples |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Kubernetes deployment guide |
| [docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common issues and solutions |
| [docs/CHANGELOG.md](docs/CHANGELOG.md) | Version history |

### Development

| Document | Description |
|----------|-------------|
| [CLAUDE.md](CLAUDE.md) | AI/LLM development guidelines |
| [docs/development/CODE_REVIEW.md](docs/development/CODE_REVIEW.md) | Code review checklist |
| [docs/development/BUGFIXES.md](docs/development/BUGFIXES.md) | Known issues and fixes |

## Migration Notice

**Logger Functionality Removed (2026-01-19)**

Logger classes migrated to `reminder-platform/shared-logger`:
- `AlexFN\NanoService\NanoLogger` → `ReminderPlatform\SharedLogger\NanoLogger`

## License

MIT License
