<?php

declare(strict_types=1);

namespace NotificationChannels\SmsTunnel\Exceptions;

class InvalidConfiguration extends \Exception
{
    public static function missingApiKey(): self
    {
        return new self(
            'SMSTunnel has no API key. Set services.smstunnel.key in config/services.php (e.g. from SMSTUNNEL_API_KEY in .env). '
            .'Create a key in your SMSTunnel dashboard.'
        );
    }

    public static function invalidValue(string $key): self
    {
        return new self(\sprintf('services.smstunnel.%s must be a string.', $key));
    }
}
