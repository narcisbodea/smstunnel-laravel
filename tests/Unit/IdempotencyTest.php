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
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Response\SendResult;

/**
 * The `Idempotency-Key` the channel gives every text, so a retried notification can't text a number twice.
 */
final class IdempotencyTest extends TestCase
{
    private const TO = '+14155550123';

    public function test_every_text_gets_a_key_made_from_the_notification_id_the_number_and_the_text(): void
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
        self::assertSame(self::keyFor($firstId, self::TO, 'Your order #1042 has shipped.'), $this->keyOf(0));
        self::assertSame(self::keyFor($secondId, self::TO, 'Your order #1042 has shipped.'), $this->keyOf(1));
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
        self::assertSame(self::keyFor($id, self::TO, 'Your order #1042 has shipped.'), $this->keyOf(0));
        self::assertSame($this->keyOf(0), $this->keyOf(1));
        self::assertCount(1, $this->transport->texts, 'one text, not two');
        $result = self::firstOf($sent)->response;
        self::assertInstanceOf(SendResult::class, $result);
        self::assertSame($this->transport->texts[0], $result->messageId, 'the retry gets the first answer back');
        self::assertTrue($result->replayed, 'and says that it sent no new text');
    }

    public function test_a_retry_that_makes_other_words_is_a_new_text_not_a_refusal(): void
    {
        // toSmsTunnel() makes a fresh code on every call, so the queue's retry renders other words.
        $codes = new \ArrayIterator(['482913', '730561']);
        $notification = $this->notification(static function () use ($codes): SmsTunnelMessage {
            $code = $codes->current();
            $codes->next();

            return SmsTunnelMessage::create("Your sign-in code is {$code}")->verificationCode();
        });
        $notification->id = 'f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34';
        $failed = $this->collect(NotificationFailed::class);
        $sent = $this->collect(NotificationSent::class);
        $this->transport->loseNextAnswer();

        try {
            (new User(self::TO))->notify($notification);
            self::fail('No exception was thrown.');
        } catch (TransportException) {
        }
        (new User(self::TO))->notify($notification); // the retry: the same notification, so the same id

        self::assertSame(self::keyFor('f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34', self::TO, 'Your sign-in code is 482913', kind: '2fa'), $this->keyOf(0));
        self::assertSame(self::keyFor('f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34', self::TO, 'Your sign-in code is 730561', kind: '2fa'), $this->keyOf(1));
        self::assertCount(2, $this->transport->texts, 'the new code goes out');
        self::assertCount(1, $this->ours($failed), 'only the lost answer failed');
        $result = self::firstOf($sent)->response;
        self::assertInstanceOf(SendResult::class, $result);
        self::assertFalse($result->replayed);
    }

    public function test_the_same_text_gets_the_same_key_and_each_thing_smstunnel_compares_changes_it(): void
    {
        config(['services.smstunnel.device_id' => 'phone-1']);
        $messages = [
            'plain' => static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your code is 123456'),
            'the same again' => static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your code is 123456'),
            'the config phone, named' => static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your code is 123456')->deviceId(' phone-1 '),
            'another phone' => static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your code is 123456')->deviceId('phone-2'),
            'a country code' => static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your code is 123456')->defaultCountryCode('+40'),
            'a verification code' => static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your code is 123456')->verificationCode(),
            'other words' => static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your code is 123457'),
        ];

        $keys = [];
        foreach ($messages as $name => $message) {
            $notification = $this->notification($message);
            $notification->id = 'n-1';
            (new User(self::TO))->notify($notification);
            $keys[$name] = $this->keyOf(\count($this->transport->requests) - 1);
        }

        self::assertSame(self::keyFor('n-1', self::TO, 'Your code is 123456', 'phone-1'), $keys['plain']);
        self::assertSame($keys['plain'], $keys['the same again']);
        self::assertSame($keys['plain'], $keys['the config phone, named'], 'the key follows what goes out');
        self::assertSame(self::keyFor('n-1', self::TO, 'Your code is 123456', 'phone-2'), $keys['another phone']);
        self::assertSame(self::keyFor('n-1', self::TO, 'Your code is 123456', 'phone-1', '+40'), $keys['a country code']);
        self::assertSame(self::keyFor('n-1', self::TO, 'Your code is 123456', 'phone-1', kind: '2fa'), $keys['a verification code']);
        self::assertSame(self::keyFor('n-1', self::TO, 'Your code is 123457', 'phone-1'), $keys['other words']);
        self::assertCount(5, array_unique($keys), 'only the same text repeats a key');
        self::assertCount(5, $this->transport->texts, 'the repeats got the first answer back');
    }

    public function test_no_two_parts_of_the_key_can_run_together(): void
    {
        // Joined with line breaks, these two would give the same key: "n-1 / number / Hi / phone-2".
        $inTheText = $this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create("Hi\nphone-2"));
        $inTheText->id = 'n-1';
        $aPhone = $this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Hi')->deviceId('phone-2'));
        $aPhone->id = 'n-1';

        (new User(self::TO))->notify($inTheText);
        (new User(self::TO))->notify($aPhone);

        self::assertSame(self::keyFor('n-1', self::TO, "Hi\nphone-2"), $this->keyOf(0));
        self::assertSame(self::keyFor('n-1', self::TO, 'Hi', 'phone-2'), $this->keyOf(1));
        self::assertNotSame($this->keyOf(0), $this->keyOf(1));
        self::assertCount(2, $this->transport->texts);
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

        $first = self::keyFor('f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34', self::TO, 'The shop opens at 10 today.');
        $second = self::keyFor('f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34', '+14155550124', 'The shop opens at 10 today.');
        self::assertSame([$first, $second, $first, $second], array_map(
            static fn (array $request): ?string => $request['headers']['Idempotency-Key'] ?? null,
            $this->transport->requests,
        ));
        self::assertCount(2, $this->transport->texts, 'one text to each number');
    }

    public function test_the_message_own_key_ties_the_text_to_your_data(): void
    {
        $sent = $this->collect(NotificationSent::class);
        $message = static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your order #1042 has shipped.')->idempotencyKey('order-1042-shipped');

        (new User(self::TO))->notify($this->notification($message));
        (new User(self::TO))->notify($this->notification($message)); // the app sent it twice

        $key = self::keyFor('order-1042-shipped', self::TO, 'Your order #1042 has shipped.');
        self::assertSame($key, $this->keyOf(0));
        self::assertSame($key, $this->keyOf(1));
        self::assertCount(1, $this->transport->texts, 'the second notification got the first answer back');
        $results = array_map(static fn (NotificationSent $event): mixed => $event->response, array_values($sent->getArrayCopy()));
        self::assertCount(2, $results);
        self::assertInstanceOf(SendResult::class, $results[0]);
        self::assertInstanceOf(SendResult::class, $results[1]);
        self::assertFalse($results[0]->replayed);
        self::assertTrue($results[1]->replayed);
    }

    public function test_the_same_own_key_with_other_words_is_a_new_text(): void
    {
        $failed = $this->collect(NotificationFailed::class);

        (new User(self::TO))->notify($this->notification(
            static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your order #1042 has shipped.')->idempotencyKey('order-1042'),
        ));
        (new User(self::TO))->notify($this->notification(
            static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your order #1042 was delivered.')->idempotencyKey('order-1042'),
        ));

        self::assertNotSame($this->keyOf(0), $this->keyOf(1));
        self::assertCount(2, $this->transport->texts);
        self::assertSame([], $this->ours($failed), 'no IDEMPOTENCY_KEY_REUSED: the key covers the words');
    }

    public function test_a_verification_code_gets_a_key_too(): void
    {
        $notification = new SignInCode('482913');
        $notification->id = 'sign-in-77';

        (new User(self::TO))->notify($notification);

        self::assertSame('https://sms.example.test/api/v1/sms/send-2fa', $this->transport->requests[0]['url']);
        self::assertSame(self::keyFor('sign-in-77', self::TO, 'Your sign-in code is 482913', kind: '2fa'), $this->keyOf(0));
    }

    public function test_a_first_answer_is_not_replayed(): void
    {
        $sent = $this->collect(NotificationSent::class);

        (new User(self::TO))->notify(new OrderShipped('Your order #1042 has shipped.'));

        $result = self::firstOf($sent)->response;
        self::assertInstanceOf(SendResult::class, $result);
        self::assertFalse($result->replayed);
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
        $ours = $this->ours($failed);
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

    /**
     * The key the channel makes: a SHA-256 of the JSON array of the message's key and what SMSTunnel compares.
     */
    public static function keyFor(
        string $key,
        string $number,
        string $text,
        ?string $deviceId = null,
        ?string $defaultCountryCode = null,
        string $kind = 'sms',
        ?string $sendAt = null,
    ): string {
        return hash('sha256', json_encode(
            [$key, $number, $text, $deviceId, $defaultCountryCode, $kind, $sendAt],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
        ));
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
    private static function firstOf(\ArrayObject $events): object
    {
        $all = $events->getArrayCopy();
        self::assertNotEmpty($all, 'No event was dispatched.');

        return array_values($all)[0];
    }

    /**
     * The NotificationFailed events this channel dispatched.
     *
     * @param  \ArrayObject<int, NotificationFailed>  $events
     * @return list<NotificationFailed>
     */
    private function ours(\ArrayObject $events): array
    {
        return array_values(array_filter(
            $events->getArrayCopy(),
            static fn (NotificationFailed $event): bool => $event->channel === 'smstunnel' && \array_key_exists('to', $event->data),
        ));
    }
}
