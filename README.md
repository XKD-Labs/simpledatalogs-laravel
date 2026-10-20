# simpledatalogs/laravel

Sends events, Eloquent model changes, sign-ins, Laravel log channels and telemetry (requests, jobs, scheduled tasks, commands, queries, outgoing HTTP, cache, mail and exceptions) to [simpledatalogs](https://simpledatalogs.com) as NDJSON with `event_id` and `ts`, so retries count once.
Everything only appends to a disk outbox, shipped after each response (or by a queue job, or the scheduled command), so requests never wait on the network.
Full guide: [simpledatalogs.com/docs/sdks/laravel](https://simpledatalogs.com/docs/sdks/laravel).

Requires PHP 8.2+ and Laravel 11 or 12.

## Install

```bash
composer require simpledatalogs/laravel
php artisan vendor:publish --tag=simpledatalogs-config
```

```dotenv
SIMPLEDATALOGS_KEY=sdl_sec_…
```

## Track events

```php
use SimpleDataLogs\Laravel\Facades\SimpleDataLogs;

SimpleDataLogs::track('signup', ['plan' => 'pro', 'seats' => 3]);
```

Each event gets an `event_id` and `ts` now, `props.app_env`, and the user's id when one is known (`userId:` argument, `SimpleDataLogs::setUserId()`, or the auth listener below). Props must be strings, numbers or booleans (backed enums send their value); anything else is skipped. `track()` only appends to the disk outbox and never throws.

## Model events (opt-in)

```php
use SimpleDataLogs\Laravel\TracksModelEvents;

class Order extends Model
{
    use TracksModelEvents;

    protected array $trackedAttributeValues = ['status']; // optional: values allowed out
}
```

| Event | Props |
|---|---|
| `order_created`, `order_deleted`, `order_restored` (soft deletes) | `model` (morph class), `model_id` |
| `order_updated` | The same, plus `changed`: the changed attribute **names**, e.g. `status,note`; an update that only touched timestamps sends nothing |

Values are never sent, except `attributes.<name>` for attributes listed in `$trackedAttributeValues` and not `$hidden`. Override `trackedEventName($action)` to rename.

## Auth events (opt-in)

```sh
SIMPLEDATALOGS_TRACK_AUTH=true
```

Sends `user_logged_in`, `user_logged_out` and `user_registered`, and puts the signed-in user's id (`getAuthIdentifier()`) as `user_id` on every event of the request, including model events. It reads the user only when the guard already resolved one, so it adds no query.

## Forward a channel

Add a tap beside the channel's own file; its parameter names the channel, because Laravel names every Monolog logger after `APP_ENV`:

```php
// config/logging.php
'partner_api' => [
    'driver' => 'daily',
    'path' => storage_path('logs/partner-api.log'),
    'level' => 'debug',
    'days' => 90,
    'tap' => [\SimpleDataLogs\Laravel\ForwardToSimpleDataLogs::class . ':partner_api'],
],
```

## Telemetry (on by default)

Once `SIMPLEDATALOGS_KEY` is set, the package records every request, queued job, scheduled task and Artisan command, with no code to add. Each one becomes **one line** (a bundle) in the same outbox, written when it ends, carrying its queries, outgoing HTTP calls, cache counts, mail, notifications and exceptions. A request with everything inside it is usually one event.

Turn it all off with one line:

```dotenv
SIMPLEDATALOGS_TELEMETRY=false
```

| Signal | Sent | Switch (`telemetry.*`) |
|---|---|---|
| Requests | Method, route pattern (`GET /orders/{order}`), route name, status, duration, peak memory, user id | `requests` |
| Queries | SQL with `?` placeholders and the connection; identical SQL folds into one child with a `count`, summed time and `max_ms` (the N+1 signal) | `queries` |
| Outgoing HTTP | `METHOD host`, status, duration (Laravel's HTTP client) | `http` |
| Cache | Hit, miss, write and forget counts per store | `cache` |
| Mail, notifications | Class name, channel, duration | `mail`, `notifications` |
| Jobs | Class, queue, connection, attempts, duration, status; joined to the trace of the request that dispatched it | `jobs` |
| Scheduled tasks | Command line, cron expression, `interval_s`, duration, status, skipped runs | `tasks` |
| Commands | Name, exit code, duration (the ship command, the scheduler and long-running workers are skipped: `ignore_commands`) | `commands` |
| Exceptions | Type, message, handled or not, up to 50 frames (`in_app` for `app/`, `routes/`, `database/`) | `exceptions` |
| Logs | Every log record gets `trace_id` and `span_id`, so log lines link to their request or job | `logs` |

Each switch also reads an environment variable, for example `SIMPLEDATALOGS_TELEMETRY_QUERIES=false`.

**Never sent:** query bindings and SQL literals, headers, cookies, request and response bodies, query strings and URL paths of outgoing calls, mail addresses, subjects and bodies, notification payloads, command arguments. Route parameters stay as `{name}` in the pattern. Cache keys are off by default; `SIMPLEDATALOGS_TELEMETRY_CACHE_KEYS=true` sends key patterns with digits and hashes replaced by `*`. `drop_context_keys` applies to telemetry props too.

### Traces

Every entry point gets a W3C trace id. An incoming `traceparent` header joins the caller's trace, and a job dispatched during a request carries the trace in its payload (`__sdl_trace`), so the job shows up under the request. Custom spans:

```php
$pdf = SimpleDataLogs::span('render-pdf', fn () => $renderer->render($invoice), ['pages' => 3]);
```

### Sampling

Every root is sent with exact totals (`summary`) and its exceptions. Its children (the waterfall) go out when it is slow, errored, or one time in `detail_every`:

| Setting | Default | Meaning |
|---|---|---|
| `SIMPLEDATALOGS_SLOW_REQUEST_MS` | `500` | Requests slower than this always send their children |
| `SIMPLEDATALOGS_SLOW_JOB_MS` | `5000` | The same for jobs, tasks and commands |
| `SIMPLEDATALOGS_DETAIL_EVERY` | `10` | Other roots send their children one time in this many |
| `SIMPLEDATALOGS_SAMPLE_RATE` | `1` | Below 1, keeps that share of roots with a matching `sample_weight`; slow and errored roots are always kept |

### Release and grouping

Bundles carry a `release`: `SIMPLEDATALOGS_RELEASE`, else the deployed git SHA (a `REVISION` file, else `.git/HEAD`), else `APP_VERSION`. To group exceptions your way, set `fingerprint` in `config/simpledatalogs.php` to a callable (or an invokable class name) that takes the `Throwable` and returns a string.

### Limits

At most 60 children per bundle after folding (the rest fold into one `(other)` child per kind), 5 exceptions, 50 frames and 8 KB per stack, 100 props, and 64 KB per bundle (over it, the children are dropped and the summary kept). Recording appends to memory and writes one line per entry point; it costs well under half a millisecond for a request with 20 queries and never throws.

Octane works as is: Octane's request events mark where each request starts and ends. Queued jobs write their bundle as soon as they finish; for busy queue workers, schedule `simpledatalogs:ship` (below) so those lines leave promptly.

## Sending: no cron needed

By default the package ships after each response: once Laravel has sent the response, it sends what is waiting for up to 3 seconds. Another process already shipping holds a lock, so busy apps still batch.

| `SIMPLEDATALOGS_DELIVERY` | What happens |
|---|---|
| `terminate` (default) | Ships after each response or Artisan command |
| `queue` | Queues one `ShipOutboxJob` (unique for 60 s) on `SIMPLEDATALOGS_QUEUE` |
| `schedule` | Leaves it to the scheduled command below |

Long-running queue workers terminate only when they stop, so for busy workers also schedule the ship command, which works with every mode:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('simpledatalogs:ship')->everyMinute()->withoutOverlapping();
```

Each run ships for about 55 seconds, so lines leave within about 5 seconds. Run one pass by hand with:

```sh
php artisan simpledatalogs:ship --once
```

## What each line holds

| Field | From |
|---|---|
| `event_id` | A UUID set when the record is written |
| `ts` | The record's time, with milliseconds and offset |
| `level` | The Monolog level, lowercased |
| `message` | The record's message |
| `props` | The context (minus `drop_context_keys`, or only `context_allowlist`), `channel`, `app_env`, `extra`, and the typed `message_fields` |

For space-separated lines such as `/catalog/products/get_by_sku 1 1`, set `message_fields` to `["endpoint", "success:bool", "attempts:int"]`.
The `queries` context key is dropped by default, because it often holds personal data; change `drop_context_keys` to suit your app.

## Delivery rules

- A batch leaves the outbox only after a `202`. At most 50 lines and 256 KB per request, gzipped.
- `413` halves the batch, and each `202` doubles it back to the configured size; a single line too large on its own moves to `outbox/rejected/`.
- `415` moves the whole batch to `outbox/rejected/` with one error-log line, and the next batch goes (never resent unchanged).
- `outbox/rejected/` rotates at 16 MB and keeps the newest two files.
- **Disk caps:** the outbox never holds more than `SIMPLEDATALOGS_MAX_OUTBOX_BYTES` (512 MB); past it the oldest unsent file goes, with one warning per process in PHP's error log. A pending file past `SIMPLEDATALOGS_MAX_FILE_BYTES` (64 MB) is handed to the shipper. These lines never got a `202`, so dropping them breaks no promise; the host's disk comes first.
- A line over 200 KB (a huge context) is rebuilt with only `channel`, `app_env`, `_truncated` and `_dropped_bytes`, and the first 8 KB of its message.
- Big backlogs stream: a claimed file is read one batch at a time, and progress is a byte offset in a sidecar file, so memory stays at about one batch and a crash re-sends only the batch in flight.
- `401` pauses shipping for 10 minutes and keeps everything; while paused, runs claim nothing, and past 200 claimed files pending files grow in place.
- `429`, `503` and network errors back off exponentially with full jitter, 1 s to 5 minutes, never sooner than `Retry-After` (capped at an hour); a wait longer than the run (over quota: an hour) pauses later runs too.
- One shipper per outbox at a time (`flock`). Logging never throws: a failed append goes to PHP's error log.

## Development

```sh
composer install
vendor/bin/phpunit
```

## Links

- Docs: https://simpledatalogs.com/docs
- Changelog: [CHANGELOG.md](CHANGELOG.md)
- Security: [SECURITY.md](SECURITY.md)
- License: [MIT](LICENSE)
