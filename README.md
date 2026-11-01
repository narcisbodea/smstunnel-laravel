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
- [Build it with an AI assistant](#build-it-with-an-ai-assistant)
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

1. Install the [SMSTunnel app from Google Play](https://play.google.com/store/apps/details?id=io.smstunnel.app) on your Android phone and sign in. Sign in at [smstunnel.io](https://smstunnel.io) on your computer with the same account: the phone appears under **Phones** (you can also link it with the QR code from **Phones → Add phone**).
2. In your SMSTunnel dashboard, open **Apps** → **Add an app** and create its key. The key can send texts and look up whether they went out (`sms:send` and `sms:read`).
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

SMSTunnel checks that the text contains the code, 4 to 10 digits (such as `482913`, `123-456` or `12-345678`), and refuses it otherwise. Codes count against a separate quota, and the text can be up to 320 characters.

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

## Build it with an AI assistant

Paste this into Claude, ChatGPT, Cursor or Copilot and replace the part in brackets. Don't paste your API key into the chat: the prompt doesn't need it.

```text
Help me send Laravel notifications as text messages with SMSTunnel.

About SMSTunnel (https://smstunnel.io): my own apps and tools hand text messages (SMS) to my
SMSTunnel account, and my own Android phone sends them from my own number, on my own SIM and plan.
The phone runs the SMSTunnel app from Google Play
(https://play.google.com/store/apps/details?id=io.smstunnel.app), signed in to my account.

What I want: [describe what should send a text, to whom, and when]

How it works:
- Install the notification channel: composer require smstunnel/smstunnel-laravel (Laravel 10 to 13,
  PHP 8.1+). The service provider registers itself and installs the PHP client
  smstunnel/smstunnel-php.
- config/services.php: 'smstunnel' => ['key' => env('SMSTUNNEL_API_KEY'), 'device_id' =>
  env('SMSTUNNEL_DEVICE_ID'), 'default_country_code' => env('SMSTUNNEL_DEFAULT_COUNTRY_CODE')]; the
  key goes in .env as SMSTUNNEL_API_KEY.
- In a notification, via() returns ['smstunnel'] and toSmsTunnel(object $notifiable) returns
  NotificationChannels\SmsTunnel\SmsTunnelMessage::create('...') or a plain string; message methods
  content(), deviceId(), defaultCountryCode(), verificationCode().
- The notifiable's routeNotificationForSmstunnel(Notification $notification): ?string returns the
  number in international format; null or '' skips it, a list gets one text per number. Not a user:
  Notification::route('smstunnel', '+14155550123')->notify(...).
- Sign-in codes: SmsTunnelMessage::create("Your sign-in code is {$code}")->verificationCode(); the
  text must contain the code as 4 to 10 digits (or 123-456), up to 320 characters.
- Failures dispatch Illuminate\Notifications\Events\NotificationFailed (data: message, exception,
  to) and rethrow, all under SmsTunnel\Exception\SmsTunnelException: AuthenticationException 401,
  PermissionException 403, RateLimitException 429 (getRetryAfter()), ApiException (400, 5xx),
  TransportException (network). Use ShouldQueue so a queue retries; with a list of numbers, a retry
  texts all of them again.
- defaultCountryCode (e.g. +44) completes numbers written without + or 00 and always takes
  precedence over the phone's own country setting. Texts up to 1600 characters; 30 send requests a
  minute. If the phone is offline, the text waits and goes out when it is back.
- The API key comes from my SMSTunnel dashboard (https://dashboard.smstunnel.io): Apps → Add an app
  → Create the key. It is shown only once and can send texts and check whether they went out.

Rules for your answer:
- Keep the API key in an environment variable or the platform's secret store (for example
  SMSTUNNEL_API_KEY). Never put it in source code, in a repository, or in code that runs in a
  browser or a mobile app: call SMSTunnel from a server. I will not paste my key in this chat; use a
  placeholder.
- Validate phone numbers before sending (international format, like +14155550123) and skip or report
  the invalid ones.
- Handle errors: log or show what went wrong, retry only after 429, 5xx or network errors and only a
  few times, and never send the same text twice.
- Only send texts people expect from me: alerts to myself, reminders, sign-in codes, updates they
  asked for. No unsolicited texts.
```

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
