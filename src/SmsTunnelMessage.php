<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel;

/**
 * One text, as a notification's `toSmsTunnel()` returns it.
 *
 * ```php
 * return SmsTunnelMessage::create("Your order #{$this->order->id} has shipped.");
 * return SmsTunnelMessage::create("Your sign-in code is {$code}")->verificationCode();
 * ```
 *
 * @phpstan-consistent-constructor
 */
class SmsTunnelMessage
{
    /** Send through this phone instead of `services.smstunnel.device_id`. */
    public ?string $deviceId = null;

    /** E.g. `+40`, for numbers given without `+` or `00`. Wins over `services.smstunnel.default_country_code`. */
    public ?string $defaultCountryCode = null;

    /** Send as a sign-in or verification code (the text must contain the code). */
    public bool $verificationCode = false;

    /**
     * What the text's `Idempotency-Key` is made from, together with the number and the text itself: your own key, null
     * for the notification's id (the default), or false to send without one.
     */
    public string|false|null $idempotencyKey = null;

    /** When SMSTunnel hands the text to your phone instead of now; null sends it now. */
    public \DateTimeInterface|string|null $sendAt = null;

    public function __construct(public string $content = '') {}

    public static function create(string $content = ''): static
    {
        return new static($content);
    }

    /** The words of the text, up to 1600 characters (320 for a verification code). */
    public function content(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /** Send through this phone (an id from your SMSTunnel dashboard or `Client::getDevices()`). */
    public function deviceId(?string $deviceId): static
    {
        $this->deviceId = $deviceId;

        return $this;
    }

    /** E.g. `+40`: completes numbers given without `+` or `00`. It always takes precedence over the phone's own setting. */
    public function defaultCountryCode(?string $defaultCountryCode): static
    {
        $this->defaultCountryCode = $defaultCountryCode;

        return $this;
    }

    /**
     * Send it as a sign-in or verification code. SMSTunnel checks that the text contains the code (e.g.
     * `123456`) and counts it against the separate verification-code quota.
     */
    public function verificationCode(bool $verificationCode = true): static
    {
        $this->verificationCode = $verificationCode;

        return $this;
    }

    public function isVerificationCode(): bool
    {
        return $this->verificationCode;
    }

    /**
     * Send the text at this time instead of now, 1 minute to 30 days ahead: SMSTunnel keeps it and hands it to your
     * phone then. A `\DateTimeInterface` (Carbon too) goes out with its own offset, so make it in the time zone you
     * mean; a string must be ISO 8601 with an offset, such as `2026-11-16T09:00:00+02:00`. Null sends it now.
     * Verification codes can't be scheduled.
     */
    public function sendAt(\DateTimeInterface|string|null $sendAt): static
    {
        $this->sendAt = $sendAt;

        return $this;
    }

    /**
     * Make the text's `Idempotency-Key` from this instead of the notification's id, for example
     * `"order-{$order->id}-shipped"`: the same order's text then goes out once per number, even when your app sends
     * the notification twice (with other words it is a new text, and goes out). Null goes back to the notification's id.
     */
    public function idempotencyKey(?string $idempotencyKey): static
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    /** Send the text without an `Idempotency-Key`, as before 1.1: a retry of the notification can text again. */
    public function withoutIdempotencyKey(): static
    {
        $this->idempotencyKey = false;

        return $this;
    }
}
