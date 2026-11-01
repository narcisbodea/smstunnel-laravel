<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Notification;
use NotificationChannels\SmsTunnel\Exceptions\CouldNotSendNotification;
use SmsTunnel\Client;
use SmsTunnel\Response\SendResult;

/**
 * Sends a notification as a text from your own phone, through your SMSTunnel account.
 *
 * Use it with `via()` returning `['smstunnel']` or `[SmsTunnelChannel::class]`; the notification needs a
 * `toSmsTunnel($notifiable)` method and the notifiable a `routeNotificationForSmstunnel()` method.
 */
class SmsTunnelChannel
{
    public const NAME = 'smstunnel';

    public function __construct(
        protected Client $client,
        protected Dispatcher $events,
        protected ?string $deviceId = null,
        protected ?string $defaultCountryCode = null,
    ) {}

    /**
     * Sends one text to each number the notifiable routes to.
     *
     * Nothing is sent when the route is empty. When a text does not go through, a `NotificationFailed` event
     * is dispatched and the exception is thrown again; with several numbers, the others are still tried first.
     *
     * @return SendResult|list<SendResult>|null the result, a list of them when the route is a list, or null when there was no number
     *
     * @throws \Throwable
     */
    public function send(mixed $notifiable, Notification $notification): SendResult|array|null
    {
        try {
            $route = $this->getRoute($notifiable, $notification);
            $numbers = $this->numbersOf($route);
            if ($numbers === []) {
                return null;
            }

            $message = $this->getMessage($notifiable, $notification);
        } catch (\Throwable $exception) {
            $this->failed($notifiable, $notification, $exception, null);

            throw $exception;
        }

        $results = [];
        $firstError = null;
        foreach ($numbers as $number) {
            try {
                $results[] = $this->sendText($number, $message);
            } catch (\Throwable $exception) {
                $this->failed($notifiable, $notification, $exception, $number);
                $firstError ??= $exception;
            }
        }

        if ($firstError !== null) {
            throw $firstError;
        }

        return \is_array($route) ? $results : $results[0];
    }

    protected function sendText(string $number, SmsTunnelMessage $message): SendResult
    {
        $deviceId = $message->deviceId ?? $this->deviceId;
        $defaultCountryCode = $message->defaultCountryCode ?? $this->defaultCountryCode;

        return $message->isVerificationCode()
            ? $this->client->sendVerificationCode($number, $message->content, $deviceId, $defaultCountryCode)
            : $this->client->sendSms($number, $message->content, $deviceId, $defaultCountryCode);
    }

    /**
     * What the notifiable routes `smstunnel` to: `routeNotificationForSmstunnel()`, or an on-demand route.
     *
     * @throws CouldNotSendNotification
     */
    protected function getRoute(mixed $notifiable, Notification $notification): mixed
    {
        if (! \is_object($notifiable) || ! method_exists($notifiable, 'routeNotificationFor')) {
            throw CouldNotSendNotification::invalidReceiver($notifiable);
        }

        return $notifiable->routeNotificationFor(self::NAME, $notification)
            ?: $notifiable->routeNotificationFor(self::class, $notification);
    }

    /**
     * @return list<string> the numbers to text, without blanks and repeats
     *
     * @throws CouldNotSendNotification
     */
    protected function numbersOf(mixed $route): array
    {
        if ($route === null || $route === '' || $route === false) {
            return [];
        }

        $numbers = [];
        foreach (\is_array($route) ? $route : [$route] as $number) {
            if (! \is_string($number) && ! \is_int($number)) {
                throw CouldNotSendNotification::invalidReceiver($route);
            }
            $number = trim((string) $number);
            if ($number !== '' && ! \in_array($number, $numbers, true)) {
                $numbers[] = $number;
            }
        }

        return $numbers;
    }

    /**
     * @throws CouldNotSendNotification
     */
    protected function getMessage(mixed $notifiable, Notification $notification): SmsTunnelMessage
    {
        if (! method_exists($notification, 'toSmsTunnel')) {
            throw CouldNotSendNotification::missingMessageMethod($notification);
        }

        $message = $notification->toSmsTunnel($notifiable);

        if (\is_string($message)) {
            return new SmsTunnelMessage($message);
        }
        if (! $message instanceof SmsTunnelMessage) {
            throw CouldNotSendNotification::invalidMessageObject($message);
        }

        return $message;
    }

    protected function failed(mixed $notifiable, Notification $notification, \Throwable $exception, ?string $number): void
    {
        $this->events->dispatch(new NotificationFailed($notifiable, $notification, self::NAME, [
            'message' => $exception->getMessage(),
            'exception' => $exception,
            'to' => $number,
        ]));
    }
}
