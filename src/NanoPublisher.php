<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Contracts\NanoPublisher as NanoPublisherContract;
use AlexFN\NanoService\Contracts\NanoServiceMessage as NanoServiceMessageContract;
use Exception;
use PhpAmqpLib\Wire\AMQPTable;

class NanoPublisher extends NanoServiceClass implements NanoPublisherContract
{
    const PUBLISHER_ENABLED = 'AMQP_PUBLISHER_ENABLED';

    private NanoServiceMessageContract $message;

    private ?int $delay = null;

    private array $meta = [];

    public function setMeta(array $data): NanoPublisherContract
    {
        $this->meta = array_merge($this->meta, $data);

        return $this;
    }

    public function setMessage(NanoServiceMessageContract $message): NanoPublisherContract
    {
        $this->message = $message;

        return $this;
    }

    public function delay(int $delay): NanoPublisherContract
    {
        $this->delay = $delay;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function publish(string $event): void
    {
        if ((bool) $this->getEnv(self::PUBLISHER_ENABLED) !== true) {
            return;
        }

        $this->message->setEvent($event);
        $this->message->set('app_id', $this->getNamespace($this->getEnv(self::MICROSERVICE_NAME)));

        if ($this->delay) {
            $this->message->set('application_headers', new AMQPTable(['x-delay' => $this->delay]));
        }

        if ($this->meta) {
            $this->message->addMeta($this->meta);
        }

        $exchange = $this->getNamespace($this->exchange);
        $this->getChannel()->basic_publish($this->message, $exchange, $event);

        $this->getChannel()->close();
        $this->getConnection()->close();

        $this->reset();
    }
}
