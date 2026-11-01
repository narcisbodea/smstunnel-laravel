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
}
