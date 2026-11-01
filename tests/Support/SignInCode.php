<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Support;

use Illuminate\Notifications\Notification;
use NotificationChannels\SmsTunnel\SmsTunnelChannel;
use NotificationChannels\SmsTunnel\SmsTunnelMessage;

class SignInCode extends Notification
{
    public function __construct(public string $code = '482913') {}

    /**
     * @return list<class-string>
     */
    public function via(mixed $notifiable): array
    {
        return [SmsTunnelChannel::class];
    }

    public function toSmsTunnel(mixed $notifiable): SmsTunnelMessage
    {
        return SmsTunnelMessage::create("Your sign-in code is {$this->code}")->verificationCode();
    }
}
