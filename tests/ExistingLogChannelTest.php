<?php

namespace KhindIq\Docling\Tests;

use KhindIq\Docling\DoclingServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class ExistingLogChannelTest extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DoclingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('logging.channels.docling', [
            'driver' => 'single',
            'path' => '/tmp/custom-docling.log',
        ]);
    }

    public function test_app_defined_channel_is_not_overwritten(): void
    {
        $this->assertSame('single', config('logging.channels.docling.driver'));
        $this->assertSame('/tmp/custom-docling.log', config('logging.channels.docling.path'));
    }
}
