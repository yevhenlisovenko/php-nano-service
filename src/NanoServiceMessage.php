<?php

namespace AlexFN\NanoService;

use AlexFN\NanoService\Contracts\NanoServiceMessage as NanoServiceMessageContract;
use AlexFN\NanoService\Enums\NanoServiceMessageStatuses;
use AlexFN\NanoService\Traits\Environment;
use Exception;
use PhpAmqpLib\Message\AMQPMessage;
use Ramsey\Uuid\Uuid;
use Spatie\Crypto\Rsa\Exceptions\CouldNotDecryptData;
use Spatie\Crypto\Rsa\PrivateKey;
use Spatie\Crypto\Rsa\PublicKey;

class NanoServiceMessage extends AMQPMessage implements NanoServiceMessageContract
{
    use Environment;

    const PRIVATE_KEY = 'AMQP_PRIVATE_KEY';

    const PUBLIC_KEY = 'AMQP_PUBLIC_KEY';

    private ?PrivateKey $private_key = null;

    private ?PublicKey $public_key = null;

    public function __construct(array|string $data = [], array $properties = [], array $config = [])
    {
        $body = is_array($data) ? json_encode(array_merge($this->dataStructure(), $data)) : $data;

        $properties = array_merge($this->defaultProperty(), $properties);

        $this->config = $config;

        parent::__construct($body, $properties);
    }

    protected function defaultProperty(): array
    {
        return [
            'message_id' => Uuid::uuid7(),
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
        ];
    }

    protected function dataStructure(): array
    {
        return [
            'meta' => [],
            'status' => [
                'code' => 'unknown',
                'data' => [],
            ],
            'payload' => [],
            'system' => [
                'is_debug' => false,
                'consumer_error' => null,
                'created_at' => $this->getTimestampWithMs(),
            ]
        ];
    }

    // Body setters/getters

    protected function addData(string $key, array $data, bool $replace = false): void
    {
        $bodyData = json_decode($this->getBody(), true);
        $result = array_replace_recursive($bodyData, [
            $key => $data,
        ]);

        if (!$replace) {
            $result = array_replace_recursive($result, $bodyData);
        }

        $this->setBody(json_encode($result));
    }

    protected function setDataAttribute(string $attribute, string $key, mixed $data): void
    {
        $bodyData = $this->getData();
        $bodyData[$attribute][$key] = $data;

        $this->setBody(json_encode($bodyData));
    }

    protected function getData(): ?array
    {
        return json_decode($this->getBody(), true);
    }

    protected function getDataAttribute(string $attribute, mixed $default = []): mixed
    {
        $data = $this->getData();

        return $data[$attribute] ?? $default;
    }

    /*
     * Public methods
     */

    // Payload

    public function addPayload(array $payload, bool $replace = false): NanoServiceMessageContract
    {
        $this->addData('payload', $payload, $replace);

        return $this;
    }

    public function addPayloadAttribute(string $attribute, array $data, bool $replace = false): NanoServiceMessageContract
    {
        $this->addData('payload', [
            $attribute => $data,
        ], $replace);

        return $this;
    }

    public function getPayload(): array
    {
        return $this->getDataAttribute('payload');
    }

    public function getPayloadAttribute(string $attribute, mixed $default = null): mixed
    {
        $payload = $this->getPayload();

        return $payload[$attribute] ?? $default;
    }

    // Status
    public function setStatus(array $payload): NanoServiceMessageContract
    {
        $this->addData('status', $payload, true);

        return $this;
    }

    public function getStatusCode(): string
    {
        $statusData = $this->getDataAttribute('status');

        return $statusData['code'] ?? '';
    }

    public function getStatusDebug(): string
    {
        $statusData = $this->getDataAttribute('status');

        return $statusData['debug'] ?? '';
    }

    public function getStatusError(): string
    {
        $statusData = $this->getDataAttribute('status');

        return $statusData['error'] ?? '';
    }

    public function setStatusCode(string $code): NanoServiceMessageContract
    {
        $this->setDataAttribute('status', 'code', $code);

        return $this;
    }

    public function getStatusData(): array
    {
        $statusData = $this->getDataAttribute('status');

        return $statusData['data'] ?? [];
    }

    public function setStatusData(array $data): NanoServiceMessageContract
    {
        $this->setDataAttribute('status', 'data', $data);

        return $this;
    }

    public function getConsumerError(): string
    {
        $system = $this->getDataAttribute('system');

        return $system['consumer_error'] ?? '';
    }

    public function setConsumerError(string $msg): NanoServiceMessageContract
    {
        $this->setDataAttribute('system', 'consumer_error', $msg);

        return $this;
    }

    public function getCreatedAt(): string
    {
        $system = $this->getDataAttribute('system');
        return $system['created_at'] ?? '';
    }

    public function setCreatedAt(string $date): NanoServiceMessageContract
    {
        $this->setDataAttribute('system', 'created_at', $date);

        return $this;
    }

    public function setStatusSuccess(): NanoServiceMessageContract
    {
        $this->setStatusCode(NanoServiceMessageStatuses::SUCCESS());

        return $this;
    }

    public function setStatusError(): NanoServiceMessageContract
    {
        $this->setStatusCode(NanoServiceMessageStatuses::ERROR());

        return $this;
    }

    public function isStatusSuccess(): bool
    {
        return NanoServiceMessageStatuses::from($this->getStatusCode())->isStatusSuccess();
    }

    // Meta

    public function addMeta(array $payload, bool $replace = false): NanoServiceMessageContract
    {
        $this->addData('meta', $payload, $replace);

        return $this;
    }

    public function addMetaAttribute(string $attribute, array $data, bool $replace = false): NanoServiceMessageContract
    {
        $this->addData('meta', [
            $attribute => $data,
        ], $replace);

        return $this;
    }

    public function getMeta(): array
    {
        return $this->getDataAttribute('meta');
    }

    public function getMetaAttribute(string $attribute, mixed $default = null): mixed
    {
        $meta = $this->getMeta();

        return $meta[$attribute] ?? $default;
    }

    // Event property
    /** @deprecated use setMessageId for hashed id */
    public function setId(string $id): NanoServiceMessageContract
    {
        $this->set('message_id', $id);

        return $this;
    }

    public function setMessageId(string $id): NanoServiceMessageContract
    {
        // Validate required environment variables
        if (!isset($_ENV['AMQP_MICROSERVICE_NAME'])) {
            throw new \RuntimeException("Missing required environment variable: AMQP_MICROSERVICE_NAME");
        }

        $serviceName = $_ENV['AMQP_MICROSERVICE_NAME'];

        $hashId = md5($serviceName . $id);

        $this->set('message_id', $hashId);

        return $this;
    }

    public function setTraceId(array $traceId): NanoServiceMessageContract
    {
        $this->setDataAttribute('system', 'trace_id', $traceId);

        return $this;
    }

    /**
     * Append a message ID to the trace chain
     *
     * Convenience method for building trace chains when creating callback/relay messages.
     * Automatically appends the given ID to the existing trace chain.
     *
     * Common pattern:
     * - When receiving a message, get its trace_id: $parentTraceIds = $message->getTraceId()
     * - When creating callback, append current ID: $callback->appendTraceId($originalMessage->getId())
     *
     * @param string $messageId Message ID to append to the trace chain
     * @return NanoServiceMessageContract Fluent interface
     */
    public function appendTraceId(string $messageId): NanoServiceMessageContract
    {
        $parentTraceIds = $this->getTraceId();
        $newTraceIds = array_merge($parentTraceIds, [$messageId]);
        $this->setTraceId($newTraceIds);

        return $this;
    }

    public function setEvent(string $event): NanoServiceMessageContract
    {
        $this->set('type', $event);

        return $this;
    }

    // Debug mode

    public function setDebug(bool $debug = true): NanoServiceMessageContract
    {
        $this->setDataAttribute('system', 'is_debug', $debug);

        return $this;
    }

    public function getDebug(): bool
    {
        $system = $this->getDataAttribute('system');

        return $system['is_debug'] ?? false;
    }

    // Get message attributes

    public function getRetryCount(): int
    {
        if ($this->has('application_headers')) {
            $headers = $this->get('application_headers')->getNativeData();
            return isset($headers['x-retry-count']) ? (int)$headers['x-retry-count'] : 0;
        } else {
            return 0;
        }
    }

    public function getId(): string
    {
        return $this->get('message_id');
    }

    public function getTraceId(): array
    {
        $system = $this->getDataAttribute('system');
        
        return $system['trace_id'] ?? [];
    }

    public function getEventName(): string
    {
        return $this->get('type');
    }

    public function getPublisherName(): string
    {
        return $this->get('app_id');
    }

    // Get tenant attributes
    public function getTenantProduct(): ?string
    {
        return $this->getMetaAttribute('product');
    }

    public function getTenantEnv(): ?string
    {
        return $this->getMetaAttribute('env');
    }

    public function getTenantSlug(): ?string
    {
        return $this->getMetaAttribute('tenant');
    }

    /**
     * @throws CouldNotDecryptData
     */
    public function getEncryptedAttribute(string $attribute, mixed $default = null): ?string
    {
        if (!$this->public_key) {
            $encodedPublicKey = $this->getEnv(self::PUBLIC_KEY);
            $decodedPublicKey = base64_decode($encodedPublicKey);
            $this->public_key = PublicKey::fromString($decodedPublicKey);
        }

        $encryptedValue = $this->getPayloadAttribute($attribute);

        if (is_null($encryptedValue)) {
            return $default;
        }

        return static::decryptValue($encryptedValue, $this->public_key);
    }

    /**
     * @param string $encryptedValue
     * @param PublicKey $publicKey
     * @return string
     * @throws CouldNotDecryptData
     */
    public static function decryptValue(string $encryptedValue, PublicKey $publicKey): string
    {
        // Decode the base64 encoded attribute
        $decodedValue = base64_decode($encryptedValue);

        // Split the encrypted attribute into chunks
        $encryptedChunks = explode('.', $decodedValue);

        // Decrypt each chunk
        $decryptedChunks = array_map(function ($chunk) use ($publicKey) {
            return $publicKey->decrypt(base64_decode($chunk));
        }, $encryptedChunks);

        // Join the decrypted chunks and base64 decode the result
        $decryptedValue = implode('', $decryptedChunks);

        return base64_decode($decryptedValue);
    }

    /**
     * @throws Exception
     */
    public function setEncryptedAttribute(string $attribute, string $value): NanoServiceMessageContract
    {
        // Encrypt the attribute using the modified encryption process
        $encryptedAttribute = $this->encryptedAttribute($value);

        // Store the encrypted attribute
        $this->setDataAttribute('payload', $attribute, $encryptedAttribute);

        return $this;
    }

    /**
     * @throws Exception
     */
    public function encryptedAttribute(string $value): string
    {
        if (!$this->private_key) {
            $encodedPrivateKey = $this->getEnv(self::PRIVATE_KEY);
            $decodedPrivateKey = base64_decode($encodedPrivateKey);
            $this->private_key = PrivateKey::fromString($decodedPrivateKey);
        }

        return static::encryptValue($value, $this->private_key);
    }

    public static function encryptValue(string $value, PrivateKey $privateKey): string
    {
        // Base64 encode the input value
        $encodedValue = base64_encode($value);

        // Split the encoded value into chunks
        $chunkSize = 117; // This size may vary depending on the key size and padding used
        $chunks = str_split($encodedValue, $chunkSize);

        // Encrypt each chunk
        $encryptedChunks = array_map(function ($chunk) use ($privateKey) {
            return base64_encode($privateKey->encrypt($chunk));
        }, $chunks);

        // Join the encrypted chunks and base64 encode the result
        return base64_encode(implode('.', $encryptedChunks));
    }

    public function getTimestampWithMs(): string
    {
        $mic = microtime(true);
        $baseFormat = date('Y-m-d H:i:s', (int)$mic);
        $milliseconds = sprintf("%03d", ($mic - floor($mic)) * 1000);
        return $baseFormat . '.' . $milliseconds;
    }
}
