<?php

namespace SimpleDataLogs\Laravel;

use Illuminate\Support\Str;
use Monolog\LogRecord;

/**
 * Turns one Monolog record into one ingest API NDJSON line, with its event_id and ts set once, now.
 */
class LineBuilder
{
    // A line longer than this is rebuilt without its context, so one huge log call never builds a body ingest refuses.
    private const MAX_LINE_BYTES = 200000;

    // The most of the message such a rebuilt line keeps.
    private const TRUNCATED_MESSAGE_BYTES = 8192;

    /**
     * Keeps the settings that shape every line.
     *
     * @param array<int, string> $messageFields
     * @param array<int, string> $dropContextKeys
     * @param array<int, string>|null $contextAllowlist
     */
    public function __construct(
        private string $appEnvironment,
        private array $messageFields = [],
        private array $dropContextKeys = [],
        private ?array $contextAllowlist = null,
    ) {
    }

    /**
     * Builds the line for a record, tagged with the channel name (the record's own when none is given); the id and time make a resend count once.
     */
    public function build(LogRecord $record, ?string $channelName = null): string
    {
        // Context first, scrubbed, then the message's own typed fields on top.
        $props = $this->scrubContext($record->context);
        $props = array_merge($props, $this->messageProps($record->message));
        $props["channel"] = $channelName ?? $record->channel;
        $props["app_env"] = $this->appEnvironment;

        // Extra (processors' output) goes under its own key, so it never hides a context value.
        if ($record->extra !== []) {
            $props["extra"] = $record->extra;
        }

        $line = [
            "event_id" => (string) Str::uuid(),
            "ts" => $record->datetime->format("Y-m-d\\TH:i:s.vP"),
            "level" => strtolower($record->level->getName()),
            "message" => $record->message,
            "props" => $props,
        ];

        $encodedLine = $this->encode($line);

        if (strlen($encodedLine) <= self::MAX_LINE_BYTES) {
            return $encodedLine;
        }

        // Too big to send: keep the id, time, level, a cut message and the channel, and say how much was dropped.
        $line["message"] = mb_strcut($record->message, 0, self::TRUNCATED_MESSAGE_BYTES);
        $line["props"] = [
            "channel" => $props["channel"],
            "app_env" => $this->appEnvironment,
            "_truncated" => true,
            "_dropped_bytes" => strlen($encodedLine),
        ];

        return $this->encode($line);
    }

    /**
     * One line as JSON that never fails: invalid UTF-8 is replaced and unencodable parts are left out.
     *
     * @param array<string, mixed> $line
     */
    private function encode(array $line): string
    {
        return json_encode($line, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * The context without dropped keys, and only allowlisted keys when an allowlist is set.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function scrubContext(array $context): array
    {
        // Exceptions are not JSON; their class and message are what a dashboard can show.
        $context = array_map(fn (mixed $value): mixed => $value instanceof \Throwable
            ? ["class" => $value::class, "message" => $value->getMessage()]
            : $value, $context);

        $context = array_diff_key($context, array_flip($this->dropContextKeys));

        if ($this->contextAllowlist === null) {
            return $context;
        }

        return array_intersect_key($context, array_flip($this->contextAllowlist));
    }

    /**
     * Splits the message on whitespace into the configured typed fields; a message that doesn't fit gives none.
     *
     * @return array<string, mixed>
     */
    private function messageProps(string $message): array
    {
        if ($this->messageFields === []) {
            return [];
        }

        $tokens = preg_split("/\\s+/", trim($message));

        if (count($tokens) !== count($this->messageFields)) {
            return [];
        }

        $props = [];

        foreach ($this->messageFields as $fieldIndex => $fieldSpec) {
            [$fieldName, $fieldType] = array_pad(explode(":", $fieldSpec, 2), 2, "string");
            $props[$fieldName] = $this->typedValue($tokens[$fieldIndex], $fieldType);
        }

        return $props;
    }

    /**
     * One token as its declared type; a token that doesn't read stays text.
     */
    private function typedValue(string $token, string $fieldType): mixed
    {
        return match ($fieldType) {
            "int" => preg_match("/^-?\\d{1,18}$/", $token) === 1 ? (int) $token : $token,
            "float" => is_numeric($token) ? (float) $token : $token,
            "bool" => in_array(strtolower($token), ["1", "true", "yes"], true)
                ? true
                : (in_array(strtolower($token), ["0", "false", "no"], true) ? false : $token),
            default => $token,
        };
    }
}
