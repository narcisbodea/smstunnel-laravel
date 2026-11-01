# Changelog

All notable changes to `smstunnel/smstunnel-laravel` are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-11-01

### Added

- `SmsTunnelChannel`: sends a notification as a text from your own phone, by name (`smstunnel`) or by class.
- `SmsTunnelMessage` with `content()`, `deviceId()`, `defaultCountryCode()` and `verificationCode()`.
- Routing with `routeNotificationForSmstunnel()` or on-demand routes; a list of numbers gets one text each.
- Configuration in `config/services.php` (`services.smstunnel.key`, `device_id`, `default_country_code`, `url`).
- `NotificationFailed` event on errors, with the exception and the number.
- Service provider with package discovery; `SmsTunnel\Client` registered in the container.
- Laravel 10, 11, 12 and 13.
