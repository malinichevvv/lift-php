<?php

declare(strict_types=1);

namespace Lift\Tests;

use Lift\Database\Connection;
use Lift\Database\Grammar;
use Lift\Http\Session\ArraySessionStore;
use Lift\Http\Session\Session;
use Lift\Middleware\CorsMiddleware;
use Lift\Queue\AbstractJob;
use Lift\Queue\DatabaseJobEnvelope;
use Lift\Queue\DatabaseQueue;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the v1.2.1 security fixes.
 */
final class SecurityFixesTest extends TestCase
{
    // -----------------------------------------------------------------
    // CORS: wildcard origin must not be combined with credentials
    // -----------------------------------------------------------------

    public function testCorsWildcardWithCredentialsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CorsMiddleware(origins: '*', credentials: true);
    }

    public function testCorsWildcardWithoutCredentialsIsAllowed(): void
    {
        $this->assertInstanceOf(CorsMiddleware::class, new CorsMiddleware(origins: '*'));
    }

    public function testCorsExplicitOriginsWithCredentialsIsAllowed(): void
    {
        $this->assertInstanceOf(
            CorsMiddleware::class,
            new CorsMiddleware(origins: ['https://app.example.com'], credentials: true),
        );
    }

    // -----------------------------------------------------------------
    // Grammar: injection signals in identifiers are rejected
    // -----------------------------------------------------------------

    public function testGrammarRejectsStackedQueryInIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Grammar('mysql'))->wrap('id; DROP TABLE users');
    }

    public function testGrammarRejectsSqlCommentInIdentifier(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Grammar('mysql'))->wrap('id) -- ');
    }

    public function testGrammarStillAllowsLegitRawExpressions(): void
    {
        $g = new Grammar('mysql');
        $this->assertSame('COUNT(*)', $g->wrap('COUNT(*)'));
        $this->assertSame('lower(name) AS n', $g->wrap('lower(name) AS n'));
    }

    public function testGrammarQuotesPlainIdentifier(): void
    {
        $this->assertSame('`users`', (new Grammar('mysql'))->wrap('users'));
        $this->assertSame('`u`.`name`', (new Grammar('mysql'))->wrap('u.name'));
    }

    // -----------------------------------------------------------------
    // Session: an unknown cookie-supplied ID is replaced (fixation defence)
    // -----------------------------------------------------------------

    public function testSessionRegeneratesUnknownCookieId(): void
    {
        $store = new ArraySessionStore();
        $_COOKIE['lift_session'] = 'attacker-fixed-id';
        try {
            $session = new Session($store);
            $session->start();
            $this->assertNotSame('attacker-fixed-id', $session->id());
        } finally {
            unset($_COOKIE['lift_session']);
        }
    }

    public function testSessionKeepsCookieIdForGenuineSession(): void
    {
        $store = new ArraySessionStore();
        (new Session($store, id: 'genuine-id'))->set('user', 'Alice')->save();

        $_COOKIE['lift_session'] = 'genuine-id';
        try {
            $session = new Session($store);
            $session->start();
            $this->assertSame('genuine-id', $session->id());
            $this->assertSame('Alice', $session->get('user'));
        } finally {
            unset($_COOKIE['lift_session']);
        }
    }

    public function testSessionKeepsExplicitlyProvidedId(): void
    {
        $session = new Session(new ArraySessionStore(), id: 'explicit-id');
        $session->start();
        $this->assertSame('explicit-id', $session->id());
    }

    // -----------------------------------------------------------------
    // Queue: an unsigned payload is rejected when a secret is configured
    // -----------------------------------------------------------------

    public function testQueueRejectsUnsignedPayloadWhenSecretConfigured(): void
    {
        $db = new Connection('sqlite::memory:');

        // Producer with no secret writes an UNSIGNED payload into the table.
        (new DatabaseQueue($db, table: 'jobs', secret: ''))->push(new SecFixDummyJob());

        // A consumer configured with a secret must refuse the unsigned row
        // rather than feeding it to unserialize().
        $signed = new DatabaseQueue($db, table: 'jobs', secret: 'queue-secret');

        $this->expectException(\RuntimeException::class);
        $signed->pop();
    }

    public function testQueueSignedRoundTripSucceeds(): void
    {
        $db = new Connection('sqlite::memory:');
        $queue = new DatabaseQueue($db, table: 'jobs', secret: 'queue-secret');
        $queue->push(new SecFixDummyJob());

        // DatabaseQueue wraps the job in a DatabaseJobEnvelope; the original
        // job is reachable via getInner(). A successful pop proves the signed
        // payload passed HMAC verification.
        $popped = $queue->pop();
        $this->assertInstanceOf(DatabaseJobEnvelope::class, $popped);
        $this->assertInstanceOf(SecFixDummyJob::class, $popped->getInner());
    }
}

/** Minimal job used by the queue regression tests. */
final class SecFixDummyJob extends AbstractJob
{
    public function handle(): void
    {
        // no-op
    }
}
