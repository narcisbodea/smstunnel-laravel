<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Unit;

use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use NotificationChannels\SmsTunnel\Exceptions\CouldNotSendNotification;
use NotificationChannels\SmsTunnel\SmsTunnelChannel;
use NotificationChannels\SmsTunnel\SmsTunnelMessage;
use NotificationChannels\SmsTunnel\Tests\Support\OrderShipped;
use NotificationChannels\SmsTunnel\Tests\Support\SignInCode;
use NotificationChannels\SmsTunnel\Tests\Support\User;
use NotificationChannels\SmsTunnel\Tests\TestCase;
use SmsTunnel\Exception\AuthenticationException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Response\SendResult;

final class SmsTunnelChannelTest extends TestCase
{
    public function test_notify_sends_the_text_to_the_routed_number(): void
    {
        $sent = $this->collect(NotificationSent::class);

        (new User('+14155550123'))->notify(new OrderShipped('Your order #1042 has shipped.'));

        self::assertCount(1, $this->transport->requests);
        $request = $this->transport->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://sms.example.test/api/v1/sms/send', $request['url']);
        self::assertSame('sk_test_key', $request['headers']['X-API-Key']);
        self::assertSame(['to' => '+14155550123', 'message' => 'Your order #1042 has shipped.'], $request['json']);

        self::assertCount(1, $sent);
        $event = self::firstOf($sent);
        self::assertSame('smstunnel', $event->channel);
        self::assertInstanceOf(SendResult::class, $event->response);
        self::assertSame(\sprintf('%024x', 1), $event->response->messageId);
    }

    public function test_the_channel_class_works_in_via_and_a_verification_code_uses_its_endpoint(): void
    {
        (new User('+14155550123'))->notify(new SignInCode('482913'));

        self::assertSame('https://sms.example.test/api/v1/sms/send-2fa', $this->transport->requests[0]['url']);
        self::assertSame(['to' => '+14155550123', 'message' => 'Your sign-in code is 482913'], $this->lastJson());
    }

    public function test_to_sms_tunnel_may_return_a_string(): void
    {
        (new User)->notify($this->notification(static fn (): string => 'Plain text works too'));

        self::assertSame('Plain text works too', $this->lastJson()['message']);
    }

    public function test_config_device_and_country_code_are_used(): void
    {
        config(['services.smstunnel.device_id' => 'phone-from-config', 'services.smstunnel.default_country_code' => '+40']);

        (new User('0721123456'))->notify(new OrderShipped('Hi'));

        self::assertSame(
            ['to' => '0721123456', 'message' => 'Hi', 'deviceId' => 'phone-from-config', 'defaultCountryCode' => '+40'],
            $this->lastJson(),
        );
    }

    public function test_the_message_device_and_country_code_win_over_the_config(): void
    {
        config(['services.smstunnel.device_id' => 'phone-from-config', 'services.smstunnel.default_country_code' => '+40']);

        (new User('0612345678'))->notify($this->notification(
            static fn (): SmsTunnelMessage => SmsTunnelMessage::create('Hi')->deviceId('other-phone')->defaultCountryCode('+33'),
        ));

        self::assertSame('other-phone', $this->lastJson()['deviceId']);
        self::assertSame('+33', $this->lastJson()['defaultCountryCode']);
    }

    public function test_on_demand_routes_work_by_name_and_by_class(): void
    {
        NotificationFacade::route('smstunnel', '+14155550124')->notify(new OrderShipped('By name'));
        NotificationFacade::route(SmsTunnelChannel::class, '+14155550125')->notify(new SignInCode('111222'));

        self::assertSame('+14155550124', $this->transport->requests[0]['json']['to']);
        self::assertSame('+14155550125', $this->transport->requests[1]['json']['to']);
    }

    public function test_nothing_is_sent_without_a_number(): void
    {
        $failed = $this->collect(NotificationFailed::class);

        (new User(null))->notify(new OrderShipped);
        (new User(''))->notify(new OrderShipped);
        (new User([]))->notify(new OrderShipped);

        self::assertSame([], $this->transport->requests);
        self::assertCount(0, $failed);
    }

    public function test_a_list_of_numbers_gets_one_text_each(): void
    {
        $sent = $this->collect(NotificationSent::class);

        (new User(['+14155550123', '+14155550124', '+14155550123', ' ']))->notify(new OrderShipped('One each'));

        self::assertSame(['+14155550123', '+14155550124'], array_map(static fn (array $request): mixed => $request['json']['to'], $this->transport->requests));
        $results = self::firstOf($sent)->response;
        self::assertIsArray($results);
        self::assertCount(2, $results);
        self::assertContainsOnlyInstancesOf(SendResult::class, $results);
    }

    public function test_an_error_dispatches_notification_failed_and_is_thrown(): void
    {
        $this->transport->failFor('+14155550123', 401, ['message' => 'Invalid or expired API key', 'error' => 'Unauthorized', 'statusCode' => 401]);
        $failed = $this->collect(NotificationFailed::class);
        try {
            (new User('+14155550123'))->notify(new OrderShipped('Failing text'));
            self::fail('No exception was thrown.');
        } catch (AuthenticationException $exception) {
            self::assertSame('Invalid or expired API key', $exception->getMessage());
        }

        $ours = $this->ours($failed);
        self::assertCount(1, $ours);
        // Laravel hands each channel a clone of the notification.
        self::assertInstanceOf(OrderShipped::class, $ours[0]->notification);
        self::assertSame('Failing text', $ours[0]->notification->text);
        self::assertInstanceOf(User::class, $ours[0]->notifiable);
        self::assertSame('Invalid or expired API key', $ours[0]->data['message']);
        self::assertSame('+14155550123', $ours[0]->data['to']);
        self::assertInstanceOf(AuthenticationException::class, $ours[0]->data['exception']);
    }

    public function test_with_several_numbers_the_others_are_still_sent_before_the_error_is_thrown(): void
    {
        $this->transport->failFor('+14155550124', 429, ['message' => 'Too Many Requests', 'statusCode' => 429]);
        $failed = $this->collect(NotificationFailed::class);

        try {
            (new User(['+14155550123', '+14155550124', '+14155550125']))->notify(new OrderShipped);
            self::fail('No exception was thrown.');
        } catch (RateLimitException) {
        }

        self::assertCount(3, $this->transport->requests);
        $ours = $this->ours($failed);
        self::assertCount(1, $ours);
        self::assertSame('+14155550124', $ours[0]->data['to']);
    }

    public function test_a_notification_without_to_sms_tunnel_fails_clearly(): void
    {
        $failed = $this->collect(NotificationFailed::class);
        $notification = new class extends Notification
        {
            /** @return list<string> */
            public function via(mixed $notifiable): array
            {
                return ['smstunnel'];
            }
        };

        try {
            (new User)->notify($notification);
            self::fail('No exception was thrown.');
        } catch (CouldNotSendNotification $exception) {
            self::assertStringContainsString('Add a toSmsTunnel($notifiable) method', $exception->getMessage());
        }

        self::assertCount(1, $this->ours($failed));
        self::assertSame([], $this->transport->requests);
    }

    public function test_a_wrong_message_type_fails_clearly(): void
    {
        $this->expectException(CouldNotSendNotification::class);
        $this->expectExceptionMessage('toSmsTunnel() returned int');

        (new User)->notify($this->notification(static fn (): int => 42));
    }

    public function test_a_wrong_route_type_fails_clearly(): void
    {
        $this->expectException(CouldNotSendNotification::class);
        $this->expectExceptionMessage('should return a phone number');

        $channel = $this->app?->make(SmsTunnelChannel::class);
        self::assertInstanceOf(SmsTunnelChannel::class, $channel);
        $channel->send((new AnonymousNotifiable)->route('smstunnel', [['nested']]), new OrderShipped);
    }

    public function test_an_empty_text_is_not_sent(): void
    {
        $failed = $this->collect(NotificationFailed::class);

        try {
            (new User)->notify(new OrderShipped(''));
            self::fail('No exception was thrown.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('The text is empty.', $exception->getMessage());
        }

        self::assertCount(1, $this->ours($failed));
        self::assertSame([], $this->transport->requests);
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
