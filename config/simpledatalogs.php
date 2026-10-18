<?php

// Settings for forwarding log channels to simpledatalogs; every value can come from the environment.
return [

    // The ingest key of the deployment these logs belong to (a secret key: this runs on servers).
    "key" => env("SIMPLEDATALOGS_KEY"),

    // The ingest endpoint; NDJSON with event_id and ts makes retries count exactly once.
    "url" => env("SIMPLEDATALOGS_URL", "https://edge.simpledatalogs.com/v1/ingest"),

    // The disk outbox: lines wait here until ingest answers 202.
    "outbox_path" => env("SIMPLEDATALOGS_OUTBOX_PATH", storage_path("simpledatalogs/outbox")),

    // The outbox never takes more disk than this (512 MB); past it the oldest unsent file goes, with one warning per process in the PHP error log.
    "max_outbox_bytes" => (int) env("SIMPLEDATALOGS_MAX_OUTBOX_BYTES", 536870912),

    // A pending file past this size (64 MB) is handed to the shipper and a new one starts.
    "max_file_bytes" => (int) env("SIMPLEDATALOGS_MAX_FILE_BYTES", 67108864),

    // One request carries at most this many lines and bytes (ingest API: stay under 256 KB).
    "batch_lines" => (int) env("SIMPLEDATALOGS_BATCH_LINES", 50),
    "batch_bytes" => (int) env("SIMPLEDATALOGS_BATCH_BYTES", 262144),

    // How long one simpledatalogs:ship run keeps shipping before it exits for the next scheduled run.
    "ship_seconds" => (int) env("SIMPLEDATALOGS_SHIP_SECONDS", 55),

    // How queued lines and events leave without cron: "terminate" ships after each response, "queue" queues a ship job, "schedule" leaves it to simpledatalogs:ship.
    "delivery" => env("SIMPLEDATALOGS_DELIVERY", "terminate"),

    // How long shipping after a response may take, in seconds (the response is already sent).
    "terminate_ship_seconds" => (int) env("SIMPLEDATALOGS_TERMINATE_SHIP_SECONDS", 3),

    // The queue the ship job goes on with "queue" delivery; null is the default queue.
    "queue" => env("SIMPLEDATALOGS_QUEUE"),

    // Sends user_logged_in, user_logged_out and user_registered, and puts the signed-in user's id on every event.
    "track_auth_events" => (bool) env("SIMPLEDATALOGS_TRACK_AUTH", false),

    // Splits the message into typed props, e.g. an API client's "endpoint success attempts" line.
    // Example: ["endpoint", "success:bool", "attempts:int"]; empty keeps the message as it is.
    "message_fields" => [],

    // Context keys never sent, e.g. "queries" often holds personal data.
    "drop_context_keys" => ["queries"],

    // Only these context keys are sent, when set; null sends every key not dropped above.
    "context_allowlist" => null,

];
