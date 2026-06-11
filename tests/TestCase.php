<?php

namespace KhindIq\Docling\Tests;

use KhindIq\Docling\DoclingServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [DoclingServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('docling.base_url', 'http://docling.test');
        $app['config']->set('docling.api_key', 'test-key');
        $app['config']->set('docling.log_channel', null);
    }
}
