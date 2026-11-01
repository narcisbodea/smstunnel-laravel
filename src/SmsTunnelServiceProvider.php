<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\SmsTunnel\Exceptions\InvalidConfiguration;
use SmsTunnel\Client;
use SmsTunnel\Transport\TransportInterface;

/**
 * Registers the `smstunnel` notification channel and an `SmsTunnel\Client` built from `config/services.php`:
 *
 * ```php
 * 'smstunnel' => [
 *     'key' => env('SMSTUNNEL_API_KEY'),
 *     'device_id' => env('SMSTUNNEL_DEVICE_ID'),                       // optional
 *     'default_country_code' => env('SMSTUNNEL_DEFAULT_COUNTRY_CODE'), // optional, e.g. +40
 * ],
 * ```
 *
 * Bind `SmsTunnel\Transport\TransportInterface` in the container to send through your own HTTP client.
 */
class SmsTunnelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Client::class, static function (Application $app): Client {
            $key = self::setting($app, 'key');
            if ($key === null) {
                throw InvalidConfiguration::missingApiKey();
            }

            return new Client(
                $key,
                self::setting($app, 'url') ?? Client::DEFAULT_BASE_URL,
                $app->bound(TransportInterface::class) ? self::resolve($app, TransportInterface::class) : null,
            );
        });

        $this->app->singleton(SmsTunnelChannel::class, static fn (Application $app): SmsTunnelChannel => new SmsTunnelChannel(
            self::resolve($app, Client::class),
            self::resolve($app, Dispatcher::class),
            self::setting($app, 'device_id'),
            self::setting($app, 'default_country_code'),
        ));

        $this->callAfterResolving(ChannelManager::class, static function (ChannelManager $channels): void {
            // Not static, and no self:: inside: the manager binds this closure to itself.
            $channels->extend(SmsTunnelChannel::NAME, function (Application $app): SmsTunnelChannel {
                /** @var SmsTunnelChannel */
                return $app->make(SmsTunnelChannel::class);
            });
        });
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $abstract
     * @return T
     */
    private static function resolve(Application $app, string $abstract): object
    {
        $instance = $app->make($abstract);
        if (! $instance instanceof $abstract) {
            throw new \LogicException(\sprintf('The container did not give a %s.', $abstract));
        }

        return $instance;
    }

    /**
     * A `services.smstunnel.*` value, or null when it is not set or empty.
     *
     * @throws InvalidConfiguration
     */
    private static function setting(Application $app, string $key): ?string
    {
        /** @var Repository $config */
        $config = $app->make('config');
        $value = $config->get('services.smstunnel.'.$key);

        if ($value === null || $value === '') {
            return null;
        }
        if (! \is_string($value)) {
            throw InvalidConfiguration::invalidValue($key);
        }

        return trim($value) === '' ? null : trim($value);
    }
}
