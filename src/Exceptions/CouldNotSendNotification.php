<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Exceptions;

use NotificationChannels\SmsTunnel\SmsTunnelMessage;

class CouldNotSendNotification extends \Exception
{
    public static function missingMessageMethod(object $notification): self
    {
        return new self(\sprintf(
            'Notification was not sent. Add a toSmsTunnel($notifiable) method to %s that returns a %s or a string.',
            $notification::class,
            SmsTunnelMessage::class,
        ));
    }

    public static function invalidMessageObject(mixed $message): self
    {
        return new self(\sprintf(
            'Notification was not sent. toSmsTunnel() returned %s; it should return a %s or a string.',
            get_debug_type($message),
            SmsTunnelMessage::class,
        ));
    }

    public static function invalidReceiver(mixed $route): self
    {
        return new self(\sprintf(
            'Notification was not sent. routeNotificationForSmstunnel() returned %s; it should return a phone number (a string) or a list of them.',
            get_debug_type($route),
        ));
    }

    public static function scheduledVerificationCode(): self
    {
        return new self(
            'Notification was not sent. Verification codes can\'t be scheduled: send them when they are asked for. '
            .'Leave out sendAt() or verificationCode().'
        );
    }

    /** The number itself stays out of the message, which ends up in logs. */
    public static function invalidNumber(): self
    {
        return new self(
            'Notification was not sent. routeNotificationForSmstunnel() returned a number with characters other than digits, spaces, '
            .'"+", "(", ")", "." and "-" (a line break or a tab, for example). Give it in international format, such as +14155550123.'
        );
    }
}
