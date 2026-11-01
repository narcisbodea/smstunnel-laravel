<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Tests\Unit;

use NotificationChannels\SmsTunnel\SmsTunnelMessage;
use PHPUnit\Framework\TestCase;

final class SmsTunnelMessageTest extends TestCase
{
    public function test_it_starts_as_a_plain_text(): void
    {
        $message = new SmsTunnelMessage('Hello');

        self::assertSame('Hello', $message->content);
        self::assertNull($message->deviceId);
        self::assertNull($message->defaultCountryCode);
        self::assertFalse($message->isVerificationCode());
    }

    public function test_the_builder_sets_every_field(): void
    {
        $message = SmsTunnelMessage::create()
            ->content('Your code is 123456')
            ->deviceId('phone-1')
            ->defaultCountryCode('+40')
            ->verificationCode();

        self::assertSame('Your code is 123456', $message->content);
        self::assertSame('phone-1', $message->deviceId);
        self::assertSame('+40', $message->defaultCountryCode);
        self::assertTrue($message->isVerificationCode());

        self::assertFalse($message->verificationCode(false)->isVerificationCode());
        self::assertNull($message->deviceId(null)->deviceId);
    }
}
