<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\E2E;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\SmsTunnel\SmsTunnelChannel;
use NotificationChannels\SmsTunnel\SmsTunnelServiceProvider;
use NotificationChannels\SmsTunnel\Tests\Support\OrderShipped;
use NotificationChannels\SmsTunnel\Tests\Support\SignInCode;
use NotificationChannels\SmsTunnel\Tests\Support\User;
use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Group;
use SmsTunnel\Client;
use SmsTunnel\Exception\AuthenticationException;
use SmsTunnel\Exception\RateLimitException;
use SmsTunnel\Response\MessageStatus;
use SmsTunnel\Response\SendResult;

/**
 * `$user->notify()` against a real SMSTunnel test server, never production. Skipped unless SMSTUNNEL_E2E=1.
 *
 *   SMSTUNNEL_E2E=1
 *   SMSTUNNEL_BASE_URL=http://127.0.0.1:3101   a test server
 *   SMSTUNNEL_API_KEY=...                     a test key with sms:send and sms:read
 *   SMSTUNNEL_DEVICE_ID=...                   optional: the test phone
 *   SMSTUNNEL_E2E_RECORD=/path/sent.jsonl     optional: every accepted text is appended here, to check the database after
 */
#[Group('e2e')]
final class LocalApiTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('SMSTUNNEL_E2E') !== '1') {
            self::markTestSkipped('Set SMSTUNNEL_E2E=1 (and SMSTUNNEL_BASE_URL, SMSTUNNEL_API_KEY) to run against a test server.');
        }

        $baseUrl = (string) getenv('SMSTUNNEL_BASE_URL');
        $host = (string) parse_url($baseUrl, \PHP_URL_HOST);
        if ($baseUrl === '' || getenv('SMSTUNNEL_API_KEY') === false) {
            self::fail('SMSTUNNEL_BASE_URL and SMSTUNNEL_API_KEY are needed for the end-to-end tests.');
        }
        if ($host === 'smstunnel.io' || str_ends_with($host, '.smstunnel.io')) {
            self::fail('The end-to-end tests never run against production. Point SMSTUNNEL_BASE_URL at a test server.');
        }

        parent::setUp();
    }

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SmsTunnelServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);
        $config->set('services.smstunnel', [
            'key' => getenv('SMSTUNNEL_API_KEY') ?: null,
            'url' => getenv('SMSTUNNEL_BASE_URL') ?: null,
            'device_id' => getenv('SMSTUNNEL_DEVICE_ID') ?: null,
        ]);
    }

    public function test_user_notify_sends_the_text_from_the_phone(): void
    {
        $sent = $this->collect(NotificationSent::class);
        $text = 'SMSTunnel Laravel channel end-to-end test '.bin2hex(random_bytes(4));

        $this->retryOnRateLimit(static fn () => (new User('+14155550123'))->notify(new OrderShipped($text)));

        self::assertCount(1, $sent);
        $result = self::firstOf($sent)->response;
        self::assertInstanceOf(SendResult::class, $result);
        self::assertMatchesRegularExpression('/^[a-f0-9]{24}$/', $result->messageId);
        self::assertFalse($result->queued, 'The test phone was offline.');
        $this->record('laravel-notify', $result->messageId, '+14155550123', $text);

        $status = $this->waitUntilSent($result->messageId);
        self::assertSame('+14155550123', $status->phoneNumber);
        self::assertContains($status->status, [MessageStatus::SENT, MessageStatus::DELIVERED]);
    }

    public function test_an_on_demand_verification_code_is_sent(): void
    {
        $sent = $this->collect(NotificationSent::class);
        $code = (string) random_int(100000, 999999);

        $this->retryOnRateLimit(static fn () => Notification::route('smstunnel', '+14155550124')->notify(new SignInCode($code)));

        $result = self::firstOf($sent)->response;
        self::assertInstanceOf(SendResult::class, $result);
        $this->record('laravel-2fa', $result->messageId, '+14155550124', "Your sign-in code is {$code}");

        self::assertSame('+14155550124', $this->waitUntilSent($result->messageId)->phoneNumber);
    }

    public function test_a_wrong_key_dispatches_notification_failed(): void
    {
        config(['services.smstunnel.key' => 'sk_live_this_key_does_not_exist_0000000000']);
        $this->app?->forgetInstance(Client::class);
        $this->app?->forgetInstance(SmsTunnelChannel::class);
        $this->app?->forgetInstance(ChannelManager::class);
        $failed = $this->collect(NotificationFailed::class);

        try {
            (new User('+14155550123'))->notify(new OrderShipped('This text must not be sent'));
            self::fail('A wrong key was accepted.');
        } catch (AuthenticationException $exception) {
            self::assertSame(401, $exception->getStatusCode());
        }

        $ours = array_values(array_filter($failed->getArrayCopy(), static fn (NotificationFailed $event): bool => $event->channel === 'smstunnel'));
        self::assertCount(1, $ours);
        self::assertInstanceOf(AuthenticationException::class, $ours[0]->data['exception']);
    }

    private function waitUntilSent(string $messageId): MessageStatus
    {
        $client = $this->app?->make(Client::class);
        self::assertInstanceOf(Client::class, $client);

        $deadline = microtime(true) + 30;
        do {
            $status = $client->getMessageStatus($messageId);
            if ($status->status !== MessageStatus::PENDING) {
                return $status;
            }
            usleep(500_000);
        } while (microtime(true) < $deadline);

        return $status;
    }

    /**
     * The test server is shared, so one 429 is waited out.
     */
    private function retryOnRateLimit(\Closure $send): void
    {
        try {
            $send();
        } catch (RateLimitException $exception) {
            sleep(min(60, max(1, $exception->getRetryAfter() ?? 15)));
            $send();
        }
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

    private function record(string $kind, string $messageId, string $to, string $text): void
    {
        $file = getenv('SMSTUNNEL_E2E_RECORD');
        if ($file === false || $file === '') {
            return;
        }

        $line = json_encode(['kind' => $kind, 'messageId' => $messageId, 'to' => $to, 'message' => $text], \JSON_THROW_ON_ERROR);
        file_put_contents($file, $line."\n", \FILE_APPEND | \LOCK_EX);
    }
}
