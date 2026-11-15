<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Unit;

use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use NotificationChannels\SmsTunnel\SmsTunnelChannel;
use NotificationChannels\SmsTunnel\SmsTunnelMessage;
use NotificationChannels\SmsTunnel\Tests\Support\OrderShipped;
use NotificationChannels\SmsTunnel\Tests\Support\SignInCode;
use NotificationChannels\SmsTunnel\Tests\Support\User;
use NotificationChannels\SmsTunnel\Tests\TestCase;
use SmsTunnel\Exception\ApiException;
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Response\SendResult;

/**
 * The `Idempotency-Key` the channel gives every text, so a retried notification can't text a number twice.
 */
final class IdempotencyTest extends TestCase
{
    private const TO = '+14155550123';

    public function test_every_text_gets_a_key_made_from_the_notification_id_and_the_number(): void
    {
        /** @var \ArrayObject<int, mixed> $ids */
        $ids = new \ArrayObject;
        Event::listen(NotificationSent::class, static function (NotificationSent $event) use ($ids): void {
            $ids->append($event->notification->id);
        });

        (new User(self::TO))->notify(new OrderShipped('Your order #1042 has shipped.'));
        (new User(self::TO))->notify(new OrderShipped('Your order #1042 has shipped.'));

        [$firstId, $secondId] = $ids->getArrayCopy();
        self::assertIsString($firstId);
        self::assertIsString($secondId);
        self::assertNotSame($firstId, $secondId, 'Laravel gives every notification its own id');
        self::assertSame(hash('sha256', $firstId."\n".self::TO), $this->keyOf(0));
        self::assertSame(hash('sha256', $secondId."\n".self::TO), $this->keyOf(1));
        self::assertCount(2, $this->transport->texts, 'two notifications, two texts');
    }

    public function test_a_queued_notification_retried_after_a_lost_answer_does_not_text_twice(): void
    {
        $id = Str::uuid()->toString();
        $notification = new OrderShipped('Your order #1042 has shipped.');
        $notification->id = $id; // as Laravel does when it queues the notification; the job keeps it
        $job = new SendQueuedNotifications(new Collection([new User(self::TO)]), $notification, ['smstunnel']);
        $manager = $this->app?->make(ChannelManager::class);
        self::assertInstanceOf(ChannelManager::class, $manager);
        $sent = $this->collect(NotificationSent::class);
        $this->transport->loseNextAnswer();

        try {
            $job->handle($manager);
            self::fail('No exception was thrown.');
        } catch (TransportException) {
        }

        // The queue runs the job again from its stored copy.
        $retry = unserialize(serialize($job));
        self::assertInstanceOf(SendQueuedNotifications::class, $retry);
        $retry->handle($manager);

        self::assertCount(2, $this->transport->requests);
        self::assertSame(hash('sha256', $id."\n".self::TO), $this->keyOf(0));
        self::assertSame($this->keyOf(0), $this->keyOf(1));
        self::assertCount(1, $this->transport->texts, 'one text, not two');
        $result = $this->firstOf($sent)->response;
        self::assertInstanceOf(SendResult::class, $result);
        self::assertSame($this->transport->texts[0], $result->messageId, 'the retry gets the first answer back');
    }

    public function test_each_number_of_a_list_gets_its_own_key_and_a_retry_texts_none_twice(): void
    {
        $notification = new OrderShipped('The shop opens at 10 today.');
        $notification->id = 'f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34';
        $user = new User([self::TO, '+14155550124']);
        $this->transport->loseNextAnswer();

        try {
            $user->notify($notification);
            self::fail('No exception was thrown.');
        } catch (TransportException) {
        }
        $user->notify($notification); // a retry: the same notification, so the same id

        $first = hash('sha256', 'f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34'."\n".self::TO);
        $second = hash('sha256', "f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34\n+14155550124");
        self::assertSame([$first, $second, $first, $second], array_map(
            static fn (array $request): ?string => $request['headers']['Idempotency-Key'] ?? null,
            $this->transport->requests,
        ));
        self::assertCount(2, $this->transport->texts, 'one text to each number');
    }

    public function test_the_message_own_key_ties_the_text_to_your_data(): void
    {
        $message = static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your order #1042 has shipped.')->idempotencyKey('order-1042-shipped');

        (new User(self::TO))->notify($this->notification($message));
        (new User(self::TO))->notify($this->notification($message)); // the app sent it twice

        $key = hash('sha256', "order-1042-shipped\n".self::TO);
        self::assertSame($key, $this->keyOf(0));
        self::assertSame($key, $this->keyOf(1));
        self::assertCount(1, $this->transport->texts, 'the second notification got the first answer back');
    }

    public function test_the_same_own_key_with_other_words_is_refused_with_its_code(): void
    {
        $failed = $this->collect(NotificationFailed::class);
        (new User(self::TO))->notify($this->notification(
            static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your order #1042 has shipped.')->idempotencyKey('order-1042'),
        ));

        try {
            (new User(self::TO))->notify($this->notification(
                static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your order #1042 was delivered.')->idempotencyKey('order-1042'),
            ));
            self::fail('No exception was thrown.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->getStatusCode());
            self::assertSame('IDEMPOTENCY_KEY_REUSED', $exception->getErrorCode());
        }

        self::assertCount(1, $this->transport->texts);
        $ours = array_values(array_filter($failed->getArrayCopy(), static fn (NotificationFailed $event): bool => $event->channel === 'smstunnel'));
        self::assertCount(1, $ours);
        self::assertSame(self::TO, $ours[0]->data['to']);
        self::assertInstanceOf(ApiException::class, $ours[0]->data['exception']);
    }

    public function test_a_verification_code_gets_a_key_too(): void
    {
        $notification = new SignInCode('482913');
        $notification->id = 'sign-in-77';

        (new User(self::TO))->notify($notification);

        self::assertSame('https://sms.example.test/api/v1/sms/send-2fa', $this->transport->requests[0]['url']);
        self::assertSame(hash('sha256', "sign-in-77\n".self::TO), $this->keyOf(0));
    }

    public function test_without_idempotency_key_sends_no_key(): void
    {
        (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Hi')->withoutIdempotencyKey()));

        self::assertCount(1, $this->transport->requests);
        self::assertNull($this->keyOf(0));
    }

    public function test_a_notification_without_an_id_is_sent_without_a_key(): void
    {
        $channel = $this->app?->make(SmsTunnelChannel::class);
        self::assertInstanceOf(SmsTunnelChannel::class, $channel);

        // Called directly, not through notify(): Laravel gave the notification no id.
        $channel->send(new User(self::TO), new OrderShipped('Hi'));

        self::assertCount(1, $this->transport->requests);
        self::assertNull($this->keyOf(0));
    }

    public function test_an_empty_own_key_fails_before_anything_is_sent(): void
    {
        $failed = $this->collect(NotificationFailed::class);

        try {
            (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Hi')->idempotencyKey('  ')));
            self::fail('No exception was thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('idempotency key is empty', $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
        $ours = array_values(array_filter($failed->getArrayCopy(), static fn (NotificationFailed $event): bool => $event->channel === 'smstunnel'));
        self::assertCount(1, $ours);
        self::assertNull($ours[0]->data['to']);
    }

    public function test_a_message_the_notification_keeps_is_not_changed(): void
    {
        $message = SmsTunnelMessage::create('Hi');

        (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => $message));

        self::assertNull($message->idempotencyKey);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $this->keyOf(0));
    }

    private function keyOf(int $request): ?string
    {
        self::assertArrayHasKey($request, $this->transport->requests);

        return $this->transport->requests[$request]['headers']['Idempotency-Key'] ?? null;
    }

    /**
     * @param  \Closure(): mixed  $toSmsTunnel
     */
    private function notification(\Closure $toSmsTunnel): Notification
    {
        return new class($toSmsTunnel) extends Notification
        {
            public function __construct(private readonly \Closure $toSmsTunnel) {}

            /** @return list<string> */
            public function via(mixed $notifiable): array
            {
                return ['smstunnel'];
            }

            public function toSmsTunnel(mixed $notifiable): mixed
            {
                return ($this->toSmsTunnel)();
            }
        };
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $event
     * @return \ArrayObject<int, T>
     */
    private function collect(string $event): \ArrayObject
    {
        /** @var \ArrayObject<int, T> $events */
        $events = new \ArrayObject;
        Event::listen($event, static function (object $fired) use ($events): void {
            $events->append($fired);
        });

        return $events;
    }

    /**
     * @template T of object
     *
     * @param  \ArrayObject<int, T>  $events
     * @return T
     */
    private function firstOf(\ArrayObject $events): object
    {
        $all = $events->getArrayCopy();
        self::assertNotEmpty($all, 'No event was dispatched.');

        return array_values($all)[0];
    }
}
