<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Support;

use Illuminate\Notifications\Notification;
use NotificationChannels\SmsTunnel\SmsTunnelMessage;

class OrderShipped extends Notification
{
    public function __construct(public string $text = 'Your order #1042 has shipped.') {}

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['smstunnel'];
    }

    public function toSmsTunnel(mixed $notifiable): SmsTunnelMessage
    {
        return SmsTunnelMessage::create($this->text);
    }
}
