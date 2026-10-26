# Changelog

All notable changes to the `simpledatalogs/laravel` package. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the package uses [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-10-26

Telemetry: requests, jobs, scheduled tasks and commands, auto-instrumented. **On by default** once `SIMPLEDATALOGS_KEY` is set; `SIMPLEDATALOGS_TELEMETRY=false` turns it off.

### Added

- Laravel 13 support (`illuminate/*` ^13, `orchestra/testbench` ^11, PHPUnit 12); Laravel 13 needs PHP 8.3+.
- Events tracked from a queue worker, an Artisan command or the scheduler carry `app_state: background`, so dashboards count them as passive sessions; the caller's own `app_state` wins.
- One bundle per request, queued job, scheduled task and Artisan command, written to the existing outbox when it ends, with its queries, outgoing HTTP calls, cache counts, mail, notifications and exceptions.
- Identical SQL folds into one child with a count, summed duration and `max_ms`; bindings and SQL literals are never sent.
- W3C trace ids: an incoming `traceparent` joins the caller's trace, and queued jobs carry the dispatching request's trace in their payload.
- `SimpleDataLogs::span()` for custom spans.
- Exceptions reported through the exception handler, inside their bundle or on their own, with up to 50 frames and an optional `fingerprint` callback.
- Every log record gets `trace_id` and `span_id`; forwarded log lines send them as top-level fields.
- Detail sampling (`slow_request_ms`, `slow_job_ms`, `detail_every`) and optional entry-point sampling (`SIMPLEDATALOGS_SAMPLE_RATE`) with `sample_weight`.
- `release` from `SIMPLEDATALOGS_RELEASE`, the deployed git SHA or `APP_VERSION`.
- One switch per signal under `telemetry.*`, and `ignore_commands` and `ignore_jobs` lists.
- Octane support through Octane's request events.

## [0.2.0] - 2026-10-19

The first public release.

### Added

- `SimpleDataLogs::track()` for events, with the user's id when known.
- The `TracksModelEvents` trait for Eloquent created, updated, deleted and restored events.
- Opt-in auth events (`SIMPLEDATALOGS_TRACK_AUTH`).
- Log channel forwarding through a Monolog tap, with `message_fields`, `drop_context_keys` and `context_allowlist`.
- A disk outbox shipped after each response, by a queued job or by `php artisan simpledatalogs:ship`, with batching, gzip, retries with jittered backoff, `Retry-After`, 401 pauses, 413 splitting and 415 set-asides.
- Disk caps: 512 MB for the outbox and 64 MB per file.
