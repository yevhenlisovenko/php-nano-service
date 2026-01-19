# Nano-Service: Event-Driven Microservices Package for PHP

## Introduction
Nano-Service is a PHP package designed for building **event-driven microservices** using **RabbitMQ** as a message broker. This package provides an abstraction layer for publishing and consuming events, facilitating communication between microservices in a **loosely coupled architecture**.

## Features
- **Publish and consume messages** through RabbitMQ
- **Event-driven architecture** for microservices
- **Message standardization** using `NanoServiceMessage`
- **Scalable & decoupled services** with message queues
- **Flexible integration** with any PHP project
- **📊 Comprehensive StatsD metrics** for observability (v6.0+)
- **🔍 Connection health monitoring** with automatic instrumentation
- **🎯 Error categorization** with bounded error types

## Installation
To install the package via Composer, run:
```sh
composer require yevhenlisovenko/nano-service
```

Ensure that RabbitMQ is installed and running. You can use Docker to start RabbitMQ quickly:
```sh
docker run -d --name rabbitmq -p 5672:5672 -p 15672:15672 rabbitmq:management
```
Access RabbitMQ at [http://localhost:15672](http://localhost:15672) (username: `guest`, password: `guest`).

## Usage

### 1. Creating a Publisher (Event Emitter)
The `NanoPublisher` class allows you to send messages to a specific queue.

```php
require 'vendor/autoload.php';

use NanoService\NanoPublisher;

$publisher = new NanoPublisher('amqp://guest:guest@localhost:5672');
$publisher->publish('order.created', ['order_id' => 123, 'amount' => 500]);
```

### 2. Creating a Consumer (Event Listener)
The `NanoConsumer` class listens for incoming messages and processes them.

```php
require 'vendor/autoload.php';

use NanoService\NanoConsumer;

$consumer = new NanoConsumer('amqp://guest:guest@localhost:5672', 'order.created');
$consumer->consume(function ($message) {
    echo "Received event: ", json_encode($message), "\n";
});
```

### 3. Standardizing Messages
The `NanoServiceMessage` class ensures consistent message formatting across services.

```php
use NanoService\NanoServiceMessage;

$message = new NanoServiceMessage('order.created', ['order_id' => 123, 'amount' => 500]);
echo $message->toJson();
```

### 4. Implementing a Microservice
The `NanoServiceClass` helps build services that listen for messages and respond accordingly.

```php
use NanoService\NanoServiceClass;

class OrderService extends NanoServiceClass {
    public function handleMessage($message) {
        echo "Processing Order: ", json_encode($message), "\n";
    }
}

$orderService = new OrderService('amqp://guest:guest@localhost:5672', 'order.created');
$orderService->start();
```

## Configuration

### RabbitMQ Settings (Required)

Configure via environment variables:

```env
AMQP_HOST=localhost
AMQP_PORT=5672
AMQP_USER=guest
AMQP_PASS=guest
AMQP_VHOST=/
AMQP_PROJECT=myproject
AMQP_MICROSERVICE_NAME=myservice
AMQP_PUBLISHER_ENABLED=true
```

### StatsD Metrics (Optional, v6.0+)

Enable comprehensive observability:

```env
STATSD_ENABLED=true              # Enable metrics (default: false)
STATSD_HOST=10.192.0.15          # StatsD server host
STATSD_PORT=8125                 # StatsD UDP port
STATSD_NAMESPACE=myservice       # Service name for metrics
STATSD_SAMPLE_OK=0.1             # 10% sampling for success metrics
APP_ENV=production               # Environment tag
```

**📖 See [docs/METRICS.md](docs/METRICS.md) for complete metrics documentation.**

**📖 See [docs/CONFIGURATION.md](docs/CONFIGURATION.md) for detailed configuration guide.**

## Deployment
To deploy your microservices, you can use Docker:
```dockerfile
FROM php:8.1-cli
WORKDIR /app
COPY . .
RUN composer install
CMD ["php", "consumer.php"]
```
Then build and run the service:
```sh
docker build -t order-service .
docker run -d order-service
```

## Metrics & Observability (v6.0+)

nano-service provides automatic StatsD metrics for:
- **Publisher metrics**: Publish rate, latency, errors, payload sizes
- **Consumer metrics**: Processing rate, retries, DLX events, ACK failures
- **Connection health**: Connection/channel status and errors

**Quick start:**
```bash
export STATSD_ENABLED=true
export STATSD_HOST=<node-ip>  # Use status.hostIP in k8s
export STATSD_NAMESPACE=myservice
```

**Metrics collected automatically - no code changes needed!**

See [docs/METRICS.md](docs/METRICS.md) for complete documentation.

---

## Documentation

- **[docs/METRICS.md](docs/METRICS.md)** - Metrics documentation and examples
- **[docs/CONFIGURATION.md](docs/CONFIGURATION.md)** - Configuration reference
- **[docs/TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md)** - Common issues and solutions
- **[CHANGELOG.md](CHANGELOG.md)** - Version history
- **[CLAUDE.md](CLAUDE.md)** - Development guidelines

---

## Migration Notice

**Logger Functionality Migrated (2026-01-19)**

The logger functionality (`NanoLogger`, `NanoLogger` contract, and `NanoNotificatorErrorCodes` enum) has been **removed** from this package and migrated to a dedicated shared package:

**New Location:** [`reminder-platform/shared-logger`](../logger/)

If your project uses the old logger classes:
- Replace `AlexFN\NanoService\NanoLogger` with `ReminderPlatform\SharedLogger\NanoLogger`
- Replace `AlexFN\NanoService\Contracts\NanoLogger` with `ReminderPlatform\SharedLogger\Contracts\NanoLogger`
- Replace `AlexFN\NanoService\Enums\NanoNotificatorErrorCodes` with `ReminderPlatform\SharedLogger\Enums\NanoNotificatorErrorCodes`

**Why this change?**
- Better separation of concerns
- No circular dependencies (shared-logger doesn't require nano-service)
- Easier to maintain and version independently

See [shared/logger/MIGRATION.md](../logger/MIGRATION.md) for migration guide.

---

## Future Enhancements
- Support for **Kafka**, **Redis Pub/Sub**, and **Google Pub/Sub**
- More **examples and tutorials**
- Automatic **reconnection to RabbitMQ** in case of failure
- OpenTelemetry tracing support

## License
Nano-Service is open-source and licensed under the MIT License.

## Contributing
Feel free to open an issue or submit a pull request to improve the package!

