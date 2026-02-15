# Docker Integration Tests - Graceful Shutdown

This directory contains Docker-based integration tests to verify graceful shutdown behavior in a realistic environment.

## Prerequisites

- Docker Engine 20.10+
- docker compose 1.29+
- Bash shell

## Quick Start

Run the graceful shutdown integration test:

```bash
cd tests/docker
./test-graceful-shutdown.sh
```

## What The Test Does

1. **Starts Infrastructure**
   - RabbitMQ container (rabbitmq:3-management-alpine)
   - PostgreSQL container (postgres:15-alpine)

2. **Builds Consumer Image**
   - Based on `php:8.2-cli-alpine`
   - Installs `pcntl`, `pdo_pgsql`, `sockets` extensions
   - Installs Composer dependencies

3. **Starts Consumer**
   - Runs `consumer.php` which listens for `user.created` events
   - Registers SIGTERM/SIGINT signal handlers

4. **Publishes Test Message**
   - Sends a test message to RabbitMQ
   - Consumer picks it up and starts processing (5 second delay)

5. **Sends SIGTERM**
   - Simulates Kubernetes pod termination
   - Sends SIGTERM to consumer process

6. **Verifies Graceful Shutdown**
   - Checks consumer completes current message
   - Checks ACK was sent
   - Checks inbox was updated
   - Checks connections were closed
   - Checks exit code is 0

## Expected Output

```
╔══════════════════════════════════════════════════════════════╗
║     Docker Integration Test - Graceful Shutdown v7.5.2      ║
╚══════════════════════════════════════════════════════════════╝

✅ Docker is running
✅ docker compose is available

🚀 Starting RabbitMQ and PostgreSQL...
⏳ Waiting for RabbitMQ to be ready...
✅ RabbitMQ is ready
⏳ Waiting for PostgreSQL to be ready...
✅ PostgreSQL is ready

📦 Building consumer container...
✅ Container built

🚀 Starting consumer in background...
✅ Consumer is running
   Consumer PID in container: 123

📨 Publishing test message to RabbitMQ...
✅ Message published successfully

⏳ Waiting for message to be received (5 seconds)...

🛑 Sending SIGTERM to consumer (simulating Kubernetes pod termination)...
⏳ Waiting for graceful shutdown (max 30 seconds)...
✅ Consumer exited

📊 Consumer exit code: 0

🔍 Checking for graceful shutdown indicators...
✅ Signal received log found
✅ Graceful shutdown completed log found

✅ EXIT CODE 0 - Clean shutdown confirmed

╔══════════════════════════════════════════════════════════════╗
║  ✅ GRACEFUL SHUTDOWN TEST PASSED                           ║
╚══════════════════════════════════════════════════════════════╝
```

## What's Being Tested

### 1. PCNTL Extension

- Verifies `pcntl` extension is installed
- Confirms signal handlers can be registered

### 2. Signal Handling

- SIGTERM is caught by PHP process
- Signal handler sets `$shutdownRequested` flag
- Log entry confirms signal was received

### 3. Message Completion

- Message processing completes before shutdown
- ACK is sent to RabbitMQ
- Inbox is marked as 'processed'

### 4. Connection Cleanup

- RabbitMQ channel closed gracefully
- RabbitMQ connection closed gracefully
- PostgreSQL connection closed gracefully

### 5. Exit Code

- Process exits with code 0 (success)
- Kubernetes sees clean termination

## Files

- `test-graceful-shutdown.sh` - Main test orchestration script
- `docker compose.test.yml` - Docker Compose configuration
- `Dockerfile.test` - Consumer container image
- `consumer.php` - Test consumer application
- `README.md` - This file

## Troubleshooting

### Test Fails With "PCNTL not available"

The Docker image must include the `pcntl` extension:

```dockerfile
RUN docker-php-ext-install pcntl
```

Verify with:

```bash
docker compose exec consumer php -m | grep pcntl
```

### Test Fails With "Exit code: 137"

Exit code 137 = SIGKILL (128 + 9)

This means:

- SIGTERM was ignored
- Kubernetes sent SIGKILL after `terminationGracePeriodSeconds`
- Graceful shutdown did NOT work

Check:

1. PCNTL extension is installed
2. Signal handlers are registered
3. `stop_grace_period` in docker compose.yml is sufficient

### RabbitMQ or PostgreSQL Won't Start

Check Docker resources:

```bash
docker system df
docker system prune -f
```

Restart Docker Desktop if needed.

## Manual Testing

Run consumer manually:

```bash
docker compose up rabbitmq postgres -d
docker compose up consumer
```

In another terminal:

```bash
# Get consumer PID
CONSUMER_ID=$(docker compose ps -q consumer)
PID=$(docker exec $CONSUMER_ID pgrep -f "php.*consumer" | head -1)

# Send SIGTERM
docker exec $CONSUMER_ID kill -TERM $PID

# Watch logs
docker compose logs -f consumer
```

## Cleanup

```bash
docker compose down -v
```

## Integration with CI/CD

Add to your CI pipeline:

```yaml
# .github/workflows/test.yml
- name: Run Graceful Shutdown Test
  run: |
    cd tests/docker
    ./test-graceful-shutdown.sh
```

## Production Verification

After deploying to production, verify graceful shutdown works:

```bash
# Get pod name
POD=$(kubectl get pods -l app=your-consumer -o jsonpath='{.items[0].metadata.name}')

# Watch logs in one terminal
kubectl logs -f $POD

# In another terminal, delete pod (triggers SIGTERM)
kubectl delete pod $POD

# Check logs for:
# - "nano_consumer_shutdown_signal_received"
# - "nano_consumer_graceful_shutdown_completed"
# - Exit code 0
```

## See Also

- [CHANGELOG.md](../../docs/CHANGELOG.md) - v7.5.2 release notes
- [CONFIGURATION.md](../../docs/CONFIGURATION.md) - Graceful shutdown configuration
- [../Unit/NanoConsumerTest.php](../Unit/NanoConsumerTest.php) - Unit tests
