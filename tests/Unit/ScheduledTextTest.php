<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Unit;

use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use NotificationChannels\SmsTunnel\Exceptions\CouldNotSendNotification;
use NotificationChannels\SmsTunnel\SmsTunnelMessage;
use NotificationChannels\SmsTunnel\Tests\Support\User;
use NotificationChannels\SmsTunnel\Tests\TestCase;
use SmsTunnel\Exception\PermissionException;
use SmsTunnel\Exception\TransportException;
use SmsTunnel\Response\ScheduledText;

/**
 * A message with `sendAt()`: SMSTunnel keeps the text and hands it to the phone at that time.
 */
final class ScheduledTextTest extends TestCase
{
    private const TO = '+14155550123';

    private const TEXT = 'Reminder: your appointment is tomorrow at 10:30.';

    public function test_a_message_with_a_send_time_is_scheduled(): void
    {
        $sent = $this->collect(NotificationSent::class);
        $when = new \DateTimeImmutable('2026-11-16 09:00', new \DateTimeZone('Europe/Bucharest'));

        (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt($when)));

        $request = $this->transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/send', $request['url']);
        self::assertSame(['to' => self::TO, 'message' => self::TEXT, 'sendAt' => '2026-11-16T09:00:00+02:00'], $request['json']);
        self::assertCount(1, $this->transport->scheduled);
        self::assertSame([], $this->transport->texts, 'nothing goes out now');

        $text = self::firstOf($sent)->response;
        self::assertInstanceOf(ScheduledText::class, $text);
        self::assertSame($this->transport->scheduled[0], $text->id);
        self::assertSame(ScheduledText::SCHEDULED, $text->status);
        self::assertSame('2026-11-16T09:00:00+02:00', $text->sendAt);
        self::assertFalse($text->replayed);
    }

    public function test_carbon_goes_out_with_its_offset(): void
    {
        $when = new Carbon('2026-11-16 02:30:00', 'America/New_York');

        (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt($when)));

        self::assertSame('2026-11-16T02:30:00-05:00', $this->lastJson()['sendAt']);
    }

    public function test_an_iso_string_goes_out_as_it_is(): void
    {
        (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt(' 2026-11-16T07:00:00Z ')));

        self::assertSame('2026-11-16T07:00:00Z', $this->lastJson()['sendAt']);
    }

    public function test_each_number_of_a_list_gets_its_own_scheduled_text_and_key(): void
    {
        $sent = $this->collect(NotificationSent::class);
        $notification = $this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt('2026-11-16T07:00:00Z'));
        $notification->id = 'n-1';

        (new User([self::TO, '+14155550124']))->notify($notification);

        $results = self::firstOf($sent)->response;
        self::assertIsArray($results);
        self::assertContainsOnlyInstancesOf(ScheduledText::class, $results);
        self::assertCount(2, $this->transport->scheduled);
        self::assertSame(IdempotencyTest::keyFor('n-1', self::TO, self::TEXT, sendAt: '2026-11-16T07:00:00Z'), $this->keyOf(0));
        self::assertSame(IdempotencyTest::keyFor('n-1', '+14155550124', self::TEXT, sendAt: '2026-11-16T07:00:00Z'), $this->keyOf(1));
    }

    public function test_the_send_time_is_part_of_the_key(): void
    {
        $times = ['2026-11-16T07:00:00Z', '2026-11-16T07:00:00Z', '2026-11-16T08:00:00Z', null];
        foreach ($times as $time) {
            $notification = $this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt($time));
            $notification->id = 'n-1';
            (new User(self::TO))->notify($notification);
        }

        self::assertSame($this->keyOf(0), $this->keyOf(1), 'the same time: a repeat');
        self::assertNotSame($this->keyOf(0), $this->keyOf(2), 'another time: another text');
        self::assertNotSame($this->keyOf(0), $this->keyOf(3), 'now: another text');
        self::assertSame(IdempotencyTest::keyFor('n-1', self::TO, self::TEXT), $this->keyOf(3));
        self::assertCount(2, $this->transport->scheduled);
        self::assertCount(1, $this->transport->texts);
    }

    public function test_a_queue_retry_after_a_lost_answer_schedules_it_once(): void
    {
        $sent = $this->collect(NotificationSent::class);
        $notification = $this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt('2026-11-16T07:00:00Z'));
        $notification->id = 'f3d8c2a4-6b1e-4c9a-8f7d-2e5b9a1c0d34';
        $this->transport->loseNextAnswer();

        try {
            (new User(self::TO))->notify($notification);
            self::fail('No exception was thrown.');
        } catch (TransportException) {
        }
        (new User(self::TO))->notify($notification);

        self::assertSame($this->keyOf(0), $this->keyOf(1));
        self::assertCount(1, $this->transport->scheduled, 'one scheduled text, not two');
        $text = self::firstOf($sent)->response;
        self::assertInstanceOf(ScheduledText::class, $text);
        self::assertSame($this->transport->scheduled[0], $text->id);
        self::assertTrue($text->replayed);
    }

    public function test_a_verification_code_cannot_be_scheduled(): void
    {
        $failed = $this->collect(NotificationFailed::class);

        try {
            (new User(self::TO))->notify($this->notification(
                static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Your sign-in code is 482913')->verificationCode()->sendAt('2026-11-16T07:00:00Z'),
            ));
            self::fail('No exception was thrown.');
        } catch (CouldNotSendNotification $exception) {
            self::assertStringContainsString("Verification codes can't be scheduled", $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
        $ours = $this->ours($failed);
        self::assertCount(1, $ours);
        self::assertNull($ours[0]->data['to']);
    }

    public function test_a_send_time_smstunnel_cannot_take_fails_before_anything_is_sent(): void
    {
        $failed = $this->collect(NotificationFailed::class);

        try {
            (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt('tomorrow at 9')));
            self::fail('No exception was thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString('sendAt must be an ISO 8601 date and time with its offset', $exception->getMessage());
        }

        self::assertSame([], $this->transport->requests);
        $ours = $this->ours($failed);
        self::assertCount(1, $ours);
        self::assertSame(self::TO, $ours[0]->data['to']);
    }

    public function test_a_full_plan_fails_the_notification_with_the_code(): void
    {
        $this->transport->failFor(self::TO, 403, [
            'statusCode' => 403,
            'error' => 'Forbidden',
            'code' => 'SCHEDULE_LIMIT_REACHED',
            'message' => 'You have as many scheduled texts as your plan allows. Wait until some go out, or cancel one.',
            'max' => 10,
        ]);
        $failed = $this->collect(NotificationFailed::class);

        try {
            (new User(self::TO))->notify($this->notification(static fn (): SmsTunnelMessage => SmsTunnelMessage::create(self::TEXT)->sendAt('2026-11-16T07:00:00Z')));
            self::fail('No exception was thrown.');
        } catch (PermissionException $exception) {
            self::assertSame('SCHEDULE_LIMIT_REACHED', $exception->getErrorCode());
        }

        $ours = $this->ours($failed);
        self::assertCount(1, $ours);
        self::assertSame(self::TO, $ours[0]->data['to']);
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
