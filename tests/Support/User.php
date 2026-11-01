<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Support;

use Illuminate\Notifications\Notifiable;

/**
 * A notifiable like an Eloquent user, without a database.
 */
class User
{
    use Notifiable;

    /**
     * @param  string|list<string>|null  $phone
     */
    public function __construct(public string|array|null $phone = '+14155550123') {}

    /**
     * @return string|list<string>|null
     */
    public function routeNotificationForSmstunnel(mixed $notification = null): string|array|null
    {
        return $this->phone;
    }

    public function getKey(): int
    {
        return 1;
    }
}
