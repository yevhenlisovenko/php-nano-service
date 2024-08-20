<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Contracts\NanoLogger as NanoLoggerContract;
use AlexFN\NanoService\Enums\NanoNotificatorErrorCodes;
use Exception;

class NanoLogger extends NanoPublisher implements NanoLoggerContract
{

    const EVENT_PREFIX = 'logs';
    private NanoServiceMessage $message;

    public function setLog(NanoServiceMessage $message): NanoLoggerContract
    {
        $this->message = $message;

        return $this;
    }

    /**
     * @throws Exception
     */
    public function processed(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('processed', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function failed(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('failed', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function delivered(NanoNotificatorErrorCodes $code = null, string $debug = null): NanoLoggerContract
    {
        if (!$code) {
            $code = NanoNotificatorErrorCodes::DELIVERED();
        }

        $this->sendEvent('delivered', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function rejected(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('rejected', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function spam_report(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('spam_report', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function expired(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('expired', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function open(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('open', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function click(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('click', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function unknown(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('unknown', $code, $debug);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function deferred(NanoNotificatorErrorCodes $code, string $debug = null): NanoLoggerContract
    {
        $this->sendEvent('deferred', $code, $debug);

        return $this;
    }

    public function publishFallback(): void
    {
        $failedEvents = $this->message->getPayload()['failed'] ?? null;
        if (empty($failedEvents)) {
            return;
        }

        foreach ($failedEvents as $failed) {
            if (isset($failed['event']) && $failed['event'] != '') {
                $nanoMessage = (new NanoServiceMessage([
                    'payload' => $failed['payload'] ?? [],
                    'encrypted' => $failed['encrypted'] ?? [],
                    'meta' => $failed['meta'] ?? [],
                    'system' => $failed['system'] ?? [],
                    'status' => $failed['status'] ?? [],
                ]));

                $nanoMessage->setId($this->message->getId());

                $this->setMessage($nanoMessage)
                    ->publish($failed['event']);
            }
        }
    }

    /**
     * @throws Exception
     */
    private function sendEvent(string $status, NanoNotificatorErrorCodes $code, string $debug = null, string $replyTo = null)
    {
        $payload = [
            'event' => $this->message->get('type'),
            'code' => $status,
            'error' => $code->getValue(),
            'debug' => $debug
        ];

        $message = new NanoServiceMessage();
        $message->setId($this->message->getId());
        $message->addMeta($this->message->getMeta());
        $message->addPayload($payload);

        $messageReplyTo = $this->message->getPayloadAttribute('reply-to');
        if ($replyTo || $messageReplyTo) {
            $this->setMessage($message)->publish($replyTo ?? $messageReplyTo);
        }
    }
}
