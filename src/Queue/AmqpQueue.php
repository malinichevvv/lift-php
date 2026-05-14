<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Queue driver backed by RabbitMQ via php-amqplib.
 *
 * php-amqplib is a soft dependency: the class can be loaded without it, but
 * every method that actually communicates with the broker will throw a
 * {@see \RuntimeException} if `PhpAmqpLib\Connection\AMQPStreamConnection`
 * is not available.
 *
 * Require it when you need this driver:
 * ```bash
 * composer require php-amqplib/php-amqplib "^3.0"
 * ```
 *
 * Delayed jobs use a per-message TTL queue with a dead-letter exchange that
 * routes expired messages to the real queue.  No RabbitMQ plugin is required.
 *
 * ```php
 * $queue = new AmqpQueue([
 *     'host'     => 'localhost',
 *     'port'     => 5672,
 *     'user'     => 'guest',
 *     'password' => 'guest',
 *     'vhost'    => '/',
 * ]);
 *
 * $queue->push(new SendEmailJob($userId));
 * $queue->later(60, new SendReminderJob($userId)); // delay 60 s
 *
 * $job = $queue->pop('default');
 * $job?->handle();
 * ```
 */
final class AmqpQueue implements QueueInterface
{
    use SerializesJobs;

    /** @var array<string, mixed> */
    private readonly array $config;

    /** Lazily-opened AMQP connection (dynamic class; typed as mixed). */
    private mixed $connection = null;

    /** Lazily-opened AMQP channel (dynamic class; typed as mixed). */
    private mixed $channel = null;

    /** @var array<string, true> Tracks which queues have been declared. */
    private array $declared = [];

    private readonly string $secret;

    /**
     * @param array<string, mixed> $config
     *   Required keys: host, port, user, password, vhost.
     *   Optional:      exchange (default: ''), prefetch (default: 1),
     *                  secret (default: '') — HMAC signing key for payloads.
     */
    public function __construct(array $config = [])
    {
        $this->secret = (string) ($config['secret'] ?? '');
        $this->config = array_merge([
            'host'     => 'localhost',
            'port'     => 5672,
            'user'     => 'guest',
            'password' => 'guest',
            'vhost'    => '/',
            'exchange' => '',
            'prefetch' => 1,
        ], $config);
    }

    /** {@inheritdoc} */
    public function push(JobInterface $job): string
    {
        $this->ensureLibrary();

        if ($job->getDelay() > 0) {
            return $this->later($job->getDelay(), $job);
        }

        $id      = $this->generateJobId('amqp');
        $payload = $this->serialiseJob($job, $id);

        $this->ensureQueue($job->getQueue());

        $messageClass = 'PhpAmqpLib\\Message\\AMQPMessage';
        $message = new $messageClass($payload, [
            'delivery_mode' => 2,
            'message_id'    => $id,
        ]);
        $this->channel()->basic_publish($message, (string) $this->config['exchange'], $job->getQueue());

        return $id;
    }

    /** {@inheritdoc} */
    public function later(int $delay, JobInterface $job): string
    {
        $this->ensureLibrary();

        $id         = $this->generateJobId('amqp');
        $payload    = $this->serialiseJob($job, $id);
        $delayQueue = $job->getQueue() . '.delayed.' . $delay;

        $this->ensureQueue($job->getQueue());
        $this->ensureDelayQueue($delayQueue, $job->getQueue(), $delay);

        $messageClass = 'PhpAmqpLib\\Message\\AMQPMessage';
        $message = new $messageClass($payload, [
            'delivery_mode' => 2,
            'message_id'    => $id,
            'expiration'    => (string) ($delay * 1000),
        ]);
        $this->channel()->basic_publish($message, (string) $this->config['exchange'], $delayQueue);

        return $id;
    }

    /** {@inheritdoc} */
    public function pop(string $queue = 'default'): ?JobInterface
    {
        $this->ensureLibrary();
        $this->ensureQueue($queue);

        $envelope = $this->channel()->basic_get($queue, false);
        if ($envelope === null) {
            return null;
        }

        $job = $this->deserialiseJob($envelope->body);
        $this->channel()->basic_ack($envelope->getDeliveryTag());
        return $job;
    }

    /** {@inheritdoc} */
    public function size(string $queue = 'default'): int
    {
        $this->ensureLibrary();

        // Re-declare passively to get the message count.
        [, $count] = $this->channel()->queue_declare($queue, true, true, false, false);
        return (int) $count;
    }

    /** {@inheritdoc} */
    public function clear(string $queue = 'default'): void
    {
        $this->ensureLibrary();
        $this->ensureQueue($queue);
        $this->channel()->queue_purge($queue);
    }

    /** Close the AMQP connection and channel when the object is destroyed. */
    public function __destruct()
    {
        if ($this->channel !== null) {
            try {
                $this->channel->close();
            } catch (\Throwable) {
            }
        }
        if ($this->connection !== null) {
            try {
                $this->connection->close();
            } catch (\Throwable) {
            }
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function channel(): mixed
    {
        if ($this->channel === null) {
            $connClass = 'PhpAmqpLib\\Connection\\AMQPStreamConnection';
            $this->connection = new $connClass(
                $this->config['host'],
                $this->config['port'],
                $this->config['user'],
                $this->config['password'],
                $this->config['vhost'],
            );
            $this->channel = $this->connection->channel();
            $this->channel->basic_qos(0, (int) $this->config['prefetch'], false);
        }
        return $this->channel;
    }

    /** Declare a durable queue once per connection. */
    private function ensureQueue(string $queue): void
    {
        if (isset($this->declared[$queue])) {
            return;
        }
        $this->channel()->queue_declare($queue, false, true, false, false);
        $this->declared[$queue] = true;
    }

    /**
     * Declare a TTL queue that dead-letters into $targetQueue after $delay seconds.
     */
    private function ensureDelayQueue(string $delayQueue, string $targetQueue, int $delay): void
    {
        if (isset($this->declared[$delayQueue])) {
            return;
        }

        $tableClass = 'PhpAmqpLib\\Wire\\AMQPTable';
        $args = new $tableClass([
            'x-message-ttl'             => $delay * 1000,
            'x-dead-letter-exchange'    => (string) $this->config['exchange'],
            'x-dead-letter-routing-key' => $targetQueue,
            'x-expires'                 => ($delay + 60) * 1000,
        ]);
        $this->channel()->queue_declare($delayQueue, false, true, false, false, false, $args);
        $this->declared[$delayQueue] = true;
    }


    private function ensureLibrary(): void
    {
        if (!class_exists('PhpAmqpLib\\Connection\\AMQPStreamConnection')) {
            throw new \RuntimeException(
                'php-amqplib is required to use AmqpQueue. ' .
                'Run: composer require php-amqplib/php-amqplib "^3.0"'
            );
        }
    }
}
