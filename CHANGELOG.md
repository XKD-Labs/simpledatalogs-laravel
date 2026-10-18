# Changelog

All notable changes to the `simpledatalogs/laravel` package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [0.2.0] - Unreleased

The first public release.

### Added

- `SimpleDataLogs::track()` for events, with the user's id when known.
- The `TracksModelEvents` trait for Eloquent created, updated, deleted and restored events.
- Opt-in auth events (`SIMPLEDATALOGS_TRACK_AUTH`).
- Log channel forwarding through a Monolog tap, with `message_fields`, `drop_context_keys` and `context_allowlist`.
- A disk outbox shipped after each response, by a queued job or by `php artisan simpledatalogs:ship`, with batching, gzip, retries with jittered backoff, `Retry-After`, 401 pauses, 413 splitting and 415 set-asides.
- Disk caps: 512 MB for the outbox and 64 MB per file.
