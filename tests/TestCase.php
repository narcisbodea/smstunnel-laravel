<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use NotificationChannels\SmsTunnel\SmsTunnelServiceProvider;
use NotificationChannels\SmsTunnel\Tests\Support\FakeTransport;
use Orchestra\Testbench\TestCase as Orchestra;
use SmsTunnel\Transport\TransportInterface;

abstract class TestCase extends Orchestra
{
    protected FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = new FakeTransport;
        $this->app?->instance(TransportInterface::class, $this->transport);
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SmsTunnelServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);
        $config->set('services.smstunnel', [
            'key' => 'sk_test_key',
            'url' => 'https://sms.example.test',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function lastJson(): array
    {
        $last = end($this->transport->requests);
        self::assertNotFalse($last, 'Nothing was sent to SMSTunnel.');

        return $last['json'];
    }
}
