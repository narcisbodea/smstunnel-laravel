# SMSTunnel notification channel for Laravel

`smstunnel/smstunnel-laravel` sends Laravel notifications as texts with **[SMSTunnel](https://smstunnel.io)**: from **your own Android phone**, from **your own number**. It works with Laravel 10, 11, 12 and 13.

```php
$user->notify(new OrderShipped($order));
```

Your app hands the text to your SMSTunnel account and your phone sends it from its own SIM, so recipients see a number they know and can reply to. The phone is linked to your account when you sign in to the SMSTunnel app (or scan the QR code from your dashboard), and you can cut an app off at any time by deleting its API key in your dashboard.

Your phone needs the SMSTunnel app from Google Play:

<a href="https://play.google.com/store/apps/details?id=io.smstunnel.app"><img src="https://smstunnel.io/google-play-badge.png" alt="Get it on Google Play" height="56"></a>

## Contents

- [Installation](#installation)
  - [Setting up SMSTunnel](#setting-up-smstunnel)
- [Usage](#usage)
  - [Available message methods](#available-message-methods)
  - [Verification codes](#verification-codes)
  - [Several numbers](#several-numbers)
  - [Failures](#failures)
  - [The client on its own](#the-client-on-its-own)
- [Notes and limits](#notes-and-limits)
- [Responsible use](#responsible-use)
- [Changelog](#changelog)
- [Testing](#testing)
- [Security](#security)
- [Credits](#credits)
- [License](#license)

## Installation

```bash
composer require smstunnel/smstunnel-laravel
```

The service provider is registered automatically (package discovery). It also installs [`smstunnel/smstunnel-php`](https://packagist.org/packages/smstunnel/smstunnel-php), the PHP client it builds on. PHP 8.1 or newer.

### Setting up SMSTunnel

1. Install the [SMSTunnel app from Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) on your Android phone and sign in. Sign in at [smstunnel.io](https://smstunnel.io) on your computer with the same account: the phone appears under **Devices** (you can also link it with the QR code from **Devices → Add Device**).
2. In your SMSTunnel dashboard, create an API key with the `sms:send` permission (add `sms:read` if you also want to look up delivery statuses).
3. Add it to `config/services.php`:

```php
'smstunnel' => [
    'key' => env('SMSTUNNEL_API_KEY'),
    'device_id' => env('SMSTUNNEL_DEVICE_ID'),                       // optional: send through this phone
    'default_country_code' => env('SMSTUNNEL_DEFAULT_COUNTRY_CODE'), // optional, e.g. +40, for numbers without + or 00
],
```

```dotenv
SMSTUNNEL_API_KEY=sk_live_...
```

Leave `device_id` empty to let SMSTunnel pick one of your phones. A `url` key points the channel at a self-hosted SMSTunnel; the default is `https://smstunnel.io`.

## Usage

Return `smstunnel` (or `SmsTunnelChannel::class`) from `via()` and add a `toSmsTunnel()` method:

```php
use Illuminate\Notifications\Notification;
use NotificationChannels\SmsTunnel\SmsTunnelMessage;

class OrderShipped extends Notification
{
    public function __construct(private Order $order) {}

    public function via(object $notifiable): array
    {
        return ['smstunnel'];
    }

    public function toSmsTunnel(object $notifiable): SmsTunnelMessage
    {
        return SmsTunnelMessage::create("Your order #{$this->order->id} has shipped.");
    }
}
```

`toSmsTunnel()` can also return a plain string.

Tell the channel which number to text with a `routeNotificationForSmstunnel()` method on the notifiable (for example your `User` model). Use the international format, such as `+14155550123`:

```php
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    public function routeNotificationForSmstunnel(Notification $notification): ?string
    {
        return $this->phone_number;
    }
}
```

When it returns `null` or an empty string, nothing is sent, so users without a number are skipped.

To text someone who is not a user, send an on-demand notification:

```php
use Illuminate\Support\Facades\Notification;

Notification::route('smstunnel', '+14155550123')->notify(new OrderShipped($order));
```

Notifications that implement `ShouldQueue` are sent from your queue like any other channel, which keeps your requests fast.

### Available message methods

| Method | Description |
| --- | --- |
| `SmsTunnelMessage::create(string $content)` | A new text. |
| `content(string $content)` | The words of the text, up to 1600 characters (10 joined SMS). |
| `deviceId(?string $deviceId)` | Send through this phone instead of `services.smstunnel.device_id`. |
| `defaultCountryCode(?string $code)` | E.g. `+40`, for numbers given without `+` or `00`. Wins over `services.smstunnel.default_country_code`. |
| `verificationCode(bool $value = true)` | Send it as a sign-in or verification code (see below). |

### Verification codes

```php
public function toSmsTunnel(object $notifiable): SmsTunnelMessage
{
    return SmsTunnelMessage::create("Your sign-in code is {$this->code}")->verificationCode();
}
```

SMSTunnel checks that the text contains the code (such as `482913` or `12-345678`) and refuses it otherwise. Codes count against a separate quota, and the text can be up to 320 characters.

### Several numbers

If `routeNotificationForSmstunnel()` returns a list of numbers, the channel sends one text to each of them (repeats are skipped). The `NotificationSent` event then carries a list of results.

### Failures

When a text does not go through, the channel dispatches Laravel's `Illuminate\Notifications\Events\NotificationFailed` event and throws the exception again, like the other notification channels. The event's `data` holds `message`, `exception` and `to` (the number). With several numbers, the others are still tried before the exception is thrown.

```php
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (NotificationFailed $event) {
    if ($event->channel === 'smstunnel') {
        logger()->warning('Text not sent', ['to' => $event->data['to'] ?? null, 'error' => $event->data['message'] ?? null]);
    }
});
```

The exceptions come from `smstunnel/smstunnel-php`, all under `SmsTunnel\Exception\SmsTunnelException`:

| Exception | When |
| --- | --- |
| `AuthenticationException` (401) | The API key is wrong, was deleted, or its account is suspended. |
| `PermissionException` (403) | The key lacks a permission, or the plan does not include the feature. |
| `RateLimitException` (429) | Too many requests; `getRetryAfter()` says how many seconds to wait. A queued notification is retried by your queue. |
| `ApiException` | Any other error answer, e.g. 400 for a number SMSTunnel cannot read or a verification text without a code. |
| `TransportException` | SMSTunnel could not be reached. |

A missing API key throws `NotificationChannels\SmsTunnel\Exceptions\InvalidConfiguration`; a notification without `toSmsTunnel()` throws `CouldNotSendNotification`.

### The client on its own

The package registers `SmsTunnel\Client` in the container, configured from `services.smstunnel`:

```php
use SmsTunnel\Client;

$status = app(Client::class)->getMessageStatus($messageId); // needs sms:read
$phones = app(Client::class)->getDevices();
```

To send through your own HTTP client (proxy, logging), bind an `SmsTunnel\Transport\TransportInterface` in a service provider, for example a `Psr18Transport`. See the [client's README](https://packagist.org/packages/smstunnel/smstunnel-php).

## Notes and limits

- **Rate limit**: sending allows 30 requests a minute.
- **Length**: up to 1600 characters; verification codes up to 320.
- **Offline phone**: the text waits on your account and goes out when the phone is back online (`SendResult::$queued` is `true`).

## Responsible use

Send only texts that people expect from you: alerts to yourself, appointment reminders, sign-in codes, order updates. Unsolicited messages from a personal SIM break most carriers' terms of service. Please make sure your usage complies with your carrier's terms and with local regulations (in the EU, that includes GDPR and ePrivacy rules on electronic communications).

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for what has changed recently.

## Testing

```bash
composer test
composer analyse       # PHPStan, level max
composer format:check  # Pint
```

The end-to-end tests run only against a test server, never production:

```bash
SMSTUNNEL_E2E=1 SMSTUNNEL_BASE_URL=http://127.0.0.1:3101 SMSTUNNEL_API_KEY=... composer test:e2e
```

## Security

If you discover a security issue, please email contact@narboweb.ro instead of opening an issue.

## Credits

- [Narcis Bodea](https://github.com/narcisbodea), NARBOWEB SRL
- Built on the conventions of [laravel-notification-channels](https://github.com/laravel-notification-channels)

This package is not affiliated with, endorsed by, or sponsored by Laravel or its owners. Laravel is a trademark of its respective owner.

## License

The MIT License (MIT), © NARBOWEB SRL. Please see [License File](LICENSE) for more information.
