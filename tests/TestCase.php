<?php
namespace Safepay\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Safepay\SafePayServiceProvider;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate');
    }

    protected function getPackageProviders($app): array
    {
        return [SafePayServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
$app['config']->set('safepay.webhook_secret', null);
        $app['config']->set('safepay.secret_key', 'test_secret_key');
        $app['config']->set('safepay.environment', 'sandbox');
        $app['config']->set('safepay.webhook_secret', 'test_webhook_secret');
    }
}