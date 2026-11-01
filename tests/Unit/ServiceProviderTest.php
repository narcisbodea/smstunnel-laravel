<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Unit;

use Illuminate\Notifications\ChannelManager;
use NotificationChannels\SmsTunnel\Exceptions\InvalidConfiguration;
use NotificationChannels\SmsTunnel\SmsTunnelChannel;
use NotificationChannels\SmsTunnel\SmsTunnelServiceProvider;
use NotificationChannels\SmsTunnel\Tests\TestCase;
use SmsTunnel\Client;

final class ServiceProviderTest extends TestCase
{
    public function test_the_channel_is_registered_by_name(): void
    {
        $manager = $this->app?->make(ChannelManager::class);
        self::assertInstanceOf(ChannelManager::class, $manager);

        self::assertInstanceOf(SmsTunnelChannel::class, $manager->driver('smstunnel'));
        self::assertSame($manager->driver('smstunnel'), $this->app?->make(SmsTunnelChannel::class));
    }

    public function test_the_client_is_built_from_the_services_config(): void
    {
        $client = $this->app?->make(Client::class);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame('https://sms.example.test', $client->getBaseUrl());
        self::assertSame($client, $this->app?->make(Client::class), 'one client per app');

        $client->getDevices();
        self::assertSame('https://sms.example.test/api/v1/devices', $this->transport->requests[0]['url']);
    }

    public function test_the_url_defaults_to_smstunnel_io(): void
    {
        config(['services.smstunnel' => ['key' => 'sk_test_key']]);

        $client = $this->app?->make(Client::class);
        self::assertInstanceOf(Client::class, $client);
        self::assertSame('https://smstunnel.io', $client->getBaseUrl());
    }

    public function test_a_missing_key_says_where_to_set_it(): void
    {
        config(['services.smstunnel' => ['key' => null]]);

        $this->expectException(InvalidConfiguration::class);
        $this->expectExceptionMessage('services.smstunnel.key');

        $this->app?->make(Client::class);
    }

    public function test_a_key_that_is_not_a_string_is_refused(): void
    {
        config(['services.smstunnel' => ['key' => ['nested']]]);

        $this->expectException(InvalidConfiguration::class);

        $this->app?->make(Client::class);
    }

    public function test_the_provider_is_auto_discovered(): void
    {
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertSame([SmsTunnelServiceProvider::class], data_get($composer, 'extra.laravel.providers'));
    }
}
