# Changelog

All notable changes to `smstunnel/smstunnel-laravel` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.2.0] - 2026-11-15

### Added

- **Scheduled texts.** `SmsTunnelMessage::sendAt(DateTimeInterface|string|null $sendAt)`: send the text at that time
  instead of now, 1 minute to 30 days ahead. A `DateTimeInterface` (Carbon too) goes out with its own offset, a string
  must be ISO 8601 with an offset. The channel passes it to `smstunnel/smstunnel-php`'s `sendSms()`, and the
  `NotificationSent` event's `response` is then a `SmsTunnel\Response\ScheduledText` (`id`, `sendAt`, `status`,
  `replayed`) instead of a `SendResult`.
- The send time is part of each text's `Idempotency-Key`, like the words: a queue retry of the same scheduled text
  schedules it once.

### Changed

- Requires `smstunnel/smstunnel-php` 1.2.
- `SmsTunnelChannel::send()` and `sendText()` may return a `ScheduledText`; their return types now include it.
- A verification code with a send time fails with `CouldNotSendNotification` before anything is sent: sign-in codes
  can't be scheduled.

## [1.1.0] - not released on its own (these changes ship in 1.2.0)

### Added

- **Safe retries.** Every text now goes out with an `Idempotency-Key`: a SHA-256 of the notification's id and
  everything SMSTunnel compares for a repeat (the number, the words, the phone, the country code and the kind of
  text), encoded as a JSON array so no two of them can run together. Laravel keeps a notification's id when the queue
  retries its job, so for 24 hours a retry of the same text gets SMSTunnel's first answer back instead of texting the
  same number twice. With a list of numbers, each number has its own key, so a retry after one number failed doesn't
  text the others again (before, it texted all of them). A retry with other words, such as a sign-in code made afresh
  in `toSmsTunnel()`, is a new text and goes out, never a refusal.
- The result in `NotificationSent` (a `SendResult`) has `replayed` (from `smstunnel/smstunnel-php` 1.1): `true` when
  SMSTunnel answered with the first answer, so no new text went out.
- `SmsTunnelMessage::idempotencyKey(?string $key)`: make the key from your own data instead, for example
  `"order-{$order->id}-shipped"`, so the same order's text goes out once per number even when your app sends the
  notification twice. An empty key fails before anything is sent.
- `SmsTunnelMessage::withoutIdempotencyKey()`: send without a key, as in 1.0.0.

### Changed

- Requires `smstunnel/smstunnel-php` 1.1, which sends the key and reads `Idempotent-Replayed`.
- A number with characters other than digits, spaces, `+`, `(`, `)`, `.` and `-` (a line break or a tab, for example)
  fails the notification with `CouldNotSendNotification` before anything is sent. The message leaves the number out.
- A server error (5xx) is kept with the key, so retries within 24 hours get the same error back instead of trying
  again: the text may have gone out before the error.
- Calling the channel's `send()` yourself, without `notify()`, gives the notification no id: those texts go out
  without a key unless the message has its own, as before.

## [1.0.0] - 2026-11-01

### Added

- `SmsTunnelChannel`: sends a notification as a text from your own phone, by name (`smstunnel`) or by class.
- `SmsTunnelMessage` with `content()`, `deviceId()`, `defaultCountryCode()` and `verificationCode()`.
- Routing with `routeNotificationForSmstunnel()` or on-demand routes; a list of numbers gets one text each.
- Configuration in `config/services.php` (`services.smstunnel.key`, `device_id`, `default_country_code`, `url`).
- `NotificationFailed` event on errors, with the exception and the number.
- Service provider with package discovery; `SmsTunnel\Client` registered in the container.
- Laravel 10, 11, 12 and 13.
