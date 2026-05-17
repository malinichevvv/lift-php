<?php

declare(strict_types=1);

namespace Lift\Queue;

/**
 * Shared job serialisation/deserialisation with optional HMAC signing.
 *
 * Wire format (when $secret is set):
 * ```json
 * {"v":1,"mac":"<sha256-hmac>","data":"<inner-json>"}
 * ```
 * where `data` is the inner JSON that is HMAC'd, containing the PHP-serialised job.
 *
 * When no secret is configured the inner JSON is stored directly (legacy mode).
 * Security: when a secret IS configured, pop operations reject any payload that
 * is not in the signed envelope — an unsigned payload is treated as a possible
 * injection attempt. Without a secret both formats are still accepted.
 *
 * @internal Used by DatabaseQueue, RedisQueue, AmqpQueue.
 */
trait SerializesJobs
{
    /**
     * Serialise a job to a storable string, optionally signed.
     *
     * @throws \RuntimeException On JSON-encoding failure.
     */
    private function serialiseJob(JobInterface $job, string $id): string
    {
        $inner = json_encode([
            'id'       => $id,
            'class'    => $job::class,
            'payload'  => serialize($job),
            'tries'    => $job->getTries(),
            'pushedAt' => time(),
        ], JSON_THROW_ON_ERROR);

        if ($this->secret !== '') {
            return json_encode([
                'v'    => 1,
                'mac'  => hash_hmac('sha256', $inner, $this->secret),
                'data' => $inner,
            ], JSON_THROW_ON_ERROR);
        }

        return $inner;
    }

    /**
     * Deserialise a raw string back to a {@see JobInterface}.
     *
     * @throws \RuntimeException On invalid payload, HMAC mismatch, or unserialise failure.
     */
    private function deserialiseJob(string $raw): JobInterface
    {
        $outer = json_decode($raw, true);

        if (!is_array($outer)) {
            throw new \RuntimeException("Corrupted queue payload (invalid JSON).");
        }

        // Signed envelope
        if (isset($outer['v'], $outer['mac'], $outer['data'])) {
            if ($this->secret === '') {
                throw new \RuntimeException(
                    'Queue payload is signed but no secret is configured on this driver. '
                    . 'Pass the same secret used when pushing.'
                );
            }
            $expected = hash_hmac('sha256', (string) $outer['data'], $this->secret);
            if (!hash_equals($expected, (string) $outer['mac'])) {
                throw new \RuntimeException(
                    'Queue payload HMAC verification failed — payload may have been tampered with.'
                );
            }
            $data = json_decode((string) $outer['data'], true);
        } elseif ($this->secret !== '') {
            // A secret is configured: every payload MUST arrive in the signed
            // envelope. Accepting an unsigned payload here would let anyone with
            // write access to the queue backend bypass HMAC verification entirely
            // and reach unserialize() — defeating the purpose of signing.
            throw new \RuntimeException(
                'Unsigned queue payload rejected: this driver is configured with a '
                . 'secret, so all payloads must be HMAC-signed. A payload without the '
                . 'signed envelope may have been injected directly into the queue backend.'
            );
        } else {
            // Unsigned / legacy payload (no secret configured on this driver).
            $data = $outer;
        }

        if (!is_array($data) || !isset($data['payload'])) {
            throw new \RuntimeException("Corrupted queue payload: missing inner structure.");
        }

        $job = unserialize((string) $data['payload'], ['allowed_classes' => true]);

        if (!$job instanceof JobInterface) {
            throw new \RuntimeException(
                "Deserialised queue payload is not a JobInterface (got: {$data['class']}). "
                . 'Ensure the job class is autoloadable on this worker.'
            );
        }

        return $job;
    }

    private function generateJobId(string $prefix = 'job'): string
    {
        return $prefix . '_' . bin2hex(random_bytes(8));
    }
}