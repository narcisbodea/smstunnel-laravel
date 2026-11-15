# Changelog

All notable changes to `smstunnel/smstunnel-laravel` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.1.0] - 2026-11-09

### Added

- **Safe retries.** Every text now goes out with an `Idempotency-Key` made from the notification's id and the
  number (a SHA-256 of both). Laravel keeps a notification's id when the queue retries its job, so for 24 hours a
  retry gets SMSTunnel's first answer back instead of texting the same number twice. With a list of numbers, each
  number has its own key, so a retry after one number failed doesn't text the others again (before, it texted all of
  them).
- `SmsTunnelMessage::idempotencyKey(?string $key)`: make the key from your own data instead, for example
  `"order-{$order->id}-shipped"`, so the same order's text goes out once per number even when your app sends the
  notification twice. An empty key fails before anything is sent.
- `SmsTunnelMessage::withoutIdempotencyKey()`: send without a key, as in 1.0.0.

### Changed

- Requires `smstunnel/smstunnel-php` 1.1, which sends the key.
- A retry with other words than the first try (for example a text that shows the time it was sent) is now refused by
  SMSTunnel: `ApiException` 422 with `getErrorCode()` `IDEMPOTENCY_KEY_REUSED`. Build the text from the notification's
  data, or use `withoutIdempotencyKey()`.
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
