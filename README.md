# Nano-Service: Event-Driven Microservices Package for PHP

## Introduction
Nano-Service is a PHP package designed for building **event-driven microservices** using **RabbitMQ** as a message broker. This package provides an abstraction layer for publishing and consuming events, facilitating communication between microservices in a **loosely coupled architecture**.

## Features
- **Publish and consume messages** through RabbitMQ
- **Event-driven architecture** for microservices
- **Message standardization** using `NanoServiceMessage`
- **Scalable & decoupled services** with message queues
- **Flexible integration** with any PHP project

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
You can configure RabbitMQ settings via environment variables:
```env
RABBITMQ_HOST=localhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASS=guest
```

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

## Future Enhancements
- Support for **Kafka**, **Redis Pub/Sub**, and **Google Pub/Sub**
- More **examples and tutorials**
- Automatic **reconnection to RabbitMQ** in case of failure

## License
Nano-Service is open-source and licensed under the MIT License.

## Contributing
Feel free to open an issue or submit a pull request to improve the package!

