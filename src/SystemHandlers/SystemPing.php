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
     * @return void
     *
     * @throws Exception
     */
    public function __invoke(NanoServiceMessage $message)
    {
        if ($url = $this->getEnv(self::CONSUMER_HEARTBEAT_URL)) {
            $this->sendHeartbeatRequest($url);
        }
    }

    /**
     * @return void
     *
     * @throws Exception
     */
    private function sendHeartbeatRequest($url)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        print_r(curl_exec($ch));

        if (curl_errno($ch)) {
            throw new Exception('Error cURL: '.curl_error($ch));
        }

        curl_close($ch);
    }
}
