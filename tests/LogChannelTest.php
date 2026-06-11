<?php

namespace KhindIq\Docling\Tests;

use KhindIq\Docling\DoclingServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

/**
 * Extends the Testbench TestCase directly (not the package TestCase, which
 * nulls the log channel) so the auto-registration path actually runs.
 */
class LogChannelTest extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DoclingServiceProvider::class];
    }

    public function test_docling_channel_is_auto_registered(): void
    {
        $channel = config('logging.channels.docling');

        $this->assertIsArray($channel);
        $this->assertSame('daily', $channel['driver']);
        $this->assertStringEndsWith('docling.log', $channel['path']);
    }
}
