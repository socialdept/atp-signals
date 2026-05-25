<?php

namespace SocialDept\AtpSignals\Tests\Unit;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;
use SocialDept\AtpSignals\Events\CommitEvent;
use SocialDept\AtpSignals\Events\SignalEvent;
use SocialDept\AtpSignals\Services\SignalRegistry;

class SignalRegistryTest extends TestCase
{
    #[Test]    public function it_matches_exact_collections()
    {
        $registry = new SignalRegistry();

        $event = new SignalEvent(
            did: 'did:plc:test',
            timeUs: time() * 1000000,
            kind: 'commit',
            commit: new CommitEvent(
                rev: 'test',
                operation: 'create',
                collection: 'app.bsky.feed.post',
                rkey: 'test',
            ),
        );

        $result = $this->invokeMethod(
            $registry,
            'matchesCollection',
            ['app.bsky.feed.post', ['app.bsky.feed.post']]
        );

        $this->assertTrue($result);
    }

    #[Test]    public function it_matches_wildcard_collections()
    {
        $registry = new SignalRegistry();

        // Test app.bsky.feed.*
        $this->assertTrue(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.feed.post', ['app.bsky.feed.*']]
            )
        );

        $this->assertTrue(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.feed.like', ['app.bsky.feed.*']]
            )
        );

        $this->assertFalse(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.graph.follow', ['app.bsky.feed.*']]
            )
        );

        // Test app.bsky.*
        $this->assertTrue(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.feed.post', ['app.bsky.*']]
            )
        );

        $this->assertTrue(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.graph.follow', ['app.bsky.*']]
            )
        );
    }

    #[Test]    public function it_matches_multiple_patterns()
    {
        $registry = new SignalRegistry();

        $patterns = [
            'app.bsky.feed.post',
            'app.bsky.graph.*',
        ];

        // Exact match
        $this->assertTrue(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.feed.post', $patterns]
            )
        );

        // Wildcard match
        $this->assertTrue(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.graph.follow', $patterns]
            )
        );

        // No match
        $this->assertFalse(
            $this->invokeMethod(
                $registry,
                'matchesCollection',
                ['app.bsky.feed.like', $patterns]
            )
        );
    }

    /**
     * Call protected/private method of a class.
     */
    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
