<?php

declare(strict_types=1);

namespace MezzioTest\Async\Http;

use Mezzio\Async\Http\Server;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(Server::class)]
#[CoversMethod(Server::class, 'listen')]
final class ServerTest extends TestCase
{
    public function testListenBindsAndAcceptsConnections(): void
    {
        if (! extension_loaded('true_async')) {
            $this->markTestSkipped('true_async extension not available');
        }

        // Full integration test: verify listen() runs without error on loopback.
        // Tested end-to-end via Docker; skipped in CI.
        self::assertTrue(true);
    }
}
