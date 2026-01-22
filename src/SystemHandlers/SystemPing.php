<?php

namespace AlexFN\NanoService\SystemHandlers;

use AlexFN\NanoService\NanoServiceMessage;
use AlexFN\NanoService\Traits\Environment;
use Exception;

class SystemPing
{
    const CONSUMER_HEARTBEAT_URL = 'AMQP_CONSUMER_HEARTBEAT_URL';

    use Environment;

    /**
     * @throws Exception
     */
    public function __invoke(NanoServiceMessage $message): void
    {
        if ($url = $this->getEnv(self::CONSUMER_HEARTBEAT_URL)) {
            $this->sendHeartbeatRequest($url);
        }
    }

    /**
     * @throws Exception
     */
    private function sendHeartbeatRequest(string $url): void
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->log($error);
            return;
        }

        $json_response = json_decode($response);

        if (json_last_error() === JSON_ERROR_NONE) {
            // JSON is valid
            if ($json_response->status !== 'ok') {
                $this->log('Error: ' . $response);
            }

            return;
        }

        $this->log('Error: ' . $response);
    }

    private function log(string $message): void
    {
        echo "[" . date("Y-m-d H:i:s") . "] HEARTBEAT | " . $message . PHP_EOL;
    }
}
