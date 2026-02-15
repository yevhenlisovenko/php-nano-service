#!/bin/bash
#
# Quick Docker test for graceful shutdown
#

set -e

cd "$(dirname "$0")"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║         Quick Docker Test - Graceful Shutdown v7.5.2        ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# Cleanup on exit
trap 'docker compose -f docker-compose.test.yml down -v 2>/dev/null || true' EXIT

# Start services
echo "🚀 Starting RabbitMQ and PostgreSQL..."
docker compose -f docker-compose.test.yml up -d rabbitmq postgres

echo "⏳ Waiting for RabbitMQ and PostgreSQL to be healthy..."
for i in {1..60}; do
    RABBIT_HEALTHY=$(docker compose -f docker-compose.test.yml ps rabbitmq --format json 2>/dev/null | grep -c '"Health":"healthy"' || echo "0")
    POSTGRES_HEALTHY=$(docker compose -f docker-compose.test.yml ps postgres --format json 2>/dev/null | grep -c '"Health":"healthy"' || echo "0")

    if [ "$RABBIT_HEALTHY" = "1" ] && [ "$POSTGRES_HEALTHY" = "1" ]; then
        echo "✅ Services are healthy"
        break
    fi

    if [ $i -eq 60 ]; then
        echo "❌ Services failed to become healthy"
        docker compose -f docker-compose.test.yml logs
        exit 1
    fi

    sleep 1
done

echo ""
echo "🚀 Starting consumer..."
docker compose -f docker-compose.test.yml up -d consumer

echo "⏳ Waiting for consumer to initialize (5 seconds)..."
sleep 5
echo ""
echo "📋 Consumer logs:"
docker compose -f docker-compose.test.yml logs consumer
echo ""

echo "🛑 Sending SIGTERM to consumer..."
docker compose -f docker-compose.test.yml exec consumer pkill -TERM php

echo "⏳ Waiting for shutdown..."
sleep 5

echo ""
echo "📋 Final logs:"
docker compose -f docker-compose.test.yml logs consumer

echo ""
echo "✅ Test complete"
