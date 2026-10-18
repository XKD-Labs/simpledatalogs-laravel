<?php

namespace SimpleDataLogs\Laravel;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;

/**
 * Opt-in on an Eloquent model: sends `<model>_created`, `_updated`, `_deleted` and `_restored` with the model type, its id and changed attribute names.
 * Values are never sent, except for attributes the model lists in `protected array $trackedAttributeValues`.
 */
trait TracksModelEvents
{
    /**
     * Registers the model event listeners; `restored` only on soft-deleting models.
     */
    public static function bootTracksModelEvents(): void
    {
        static::created(fn (Model $model) => $model->trackModelEvent("created"));
        static::updated(fn (Model $model) => $model->trackModelEvent("updated", array_keys($model->getChanges())));
        static::deleted(fn (Model $model) => $model->trackModelEvent("deleted"));

        // Only SoftDeletes adds the restored event.
        if (method_exists(static::class, "restored")) {
            static::restored(fn (Model $model) => $model->trackModelEvent("restored"));
        }
    }

    /**
     * Sends one model event; an update that only touched timestamps sends nothing.
     *
     * @param array<int, string> $changedAttributeNames
     */
    public function trackModelEvent(string $action, array $changedAttributeNames = []): void
    {
        // Timestamps and the soft-delete column change on every save or restore; they say nothing on their own.
        $changedAttributeNames = array_values(array_diff($changedAttributeNames, $this->untrackedAttributeNames()));

        if ($action === "updated" && $changedAttributeNames === []) {
            return;
        }

        $props = [
            "model" => $this->getMorphClass(),
            "model_id" => (string) $this->getKey(),
        ];

        if ($changedAttributeNames !== []) {
            $props["changed"] = implode(",", $changedAttributeNames);
        }

        // Only whitelisted, visible attributes ever send a value.
        foreach ($this->trackedAttributeValueNames() as $attributeName) {
            if (! in_array($attributeName, $this->getHidden(), true)) {
                $props["attributes." . $attributeName] = $this->getAttribute($attributeName);
            }
        }

        app(EventTracker::class)->track($this->trackedEventName($action), $props);
    }

    /**
     * The event name for an action, e.g. `order_created`; override to rename.
     */
    public function trackedEventName(string $action): string
    {
        return Str::snake(class_basename($this)) . "_" . $action;
    }

    /**
     * The attributes whose values may be sent, from the model's `$trackedAttributeValues`.
     *
     * @return array<int, string>
     */
    public function trackedAttributeValueNames(): array
    {
        return property_exists($this, "trackedAttributeValues") ? array_values((array) $this->trackedAttributeValues) : [];
    }

    /**
     * Columns left out of `changed`: the timestamps and the soft-delete column.
     *
     * @return array<int, string>
     */
    private function untrackedAttributeNames(): array
    {
        $untrackedNames = [$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()];

        if (method_exists($this, "getDeletedAtColumn")) {
            $untrackedNames[] = $this->getDeletedAtColumn();
        }

        return array_filter($untrackedNames);
    }
}
