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
 *
 * Every text goes out with an `Idempotency-Key` made from the notification's id (or the message's own key) and the
 * number. Laravel keeps a notification's id when the queue retries it, so for 24 hours a retry gets the first answer
 * back from SMSTunnel instead of texting the same number twice.
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

            $message = $this->withDefaultIdempotencyKey($this->getMessage($notifiable, $notification), $notification);
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
        $idempotencyKey = $this->idempotencyKeyFor($number, $message);

        return $message->isVerificationCode()
            ? $this->client->sendVerificationCode($number, $message->content, $deviceId, $defaultCountryCode, $idempotencyKey)
            : $this->client->sendSms($number, $message->content, $deviceId, $defaultCountryCode, $idempotencyKey);
    }

    /**
     * The message with what its texts' keys are made from: its own key, or else the notification's id (on a copy,
     * so a message the notification keeps is never changed). Without either, the texts go out without a key.
     *
     * @throws \InvalidArgumentException when the message's own key is empty
     */
    protected function withDefaultIdempotencyKey(SmsTunnelMessage $message, Notification $notification): SmsTunnelMessage
    {
        if (\is_string($message->idempotencyKey)) {
            if (trim($message->idempotencyKey) === '') {
                throw new \InvalidArgumentException('The idempotency key is empty. Leave it out to use the notification\'s id, or call withoutIdempotencyKey().');
            }

            return $message;
        }

        /** @var mixed $id Laravel sets it when it sends or queues the notification; null when the channel is called directly. */
        $id = $notification->id;
        if ($message->idempotencyKey === false || (! \is_string($id) && ! \is_int($id)) || trim((string) $id) === '') {
            return $message;
        }

        return (clone $message)->idempotencyKey((string) $id);
    }

    /**
     * The `Idempotency-Key` for one number: a SHA-256 of the message's key and the number, so each number of a list
     * gets its own and the same text to the same number always gets the same one. Null when the message has no key.
     */
    protected function idempotencyKeyFor(string $number, SmsTunnelMessage $message): ?string
    {
        return \is_string($message->idempotencyKey) ? hash('sha256', $message->idempotencyKey."\n".$number) : null;
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
