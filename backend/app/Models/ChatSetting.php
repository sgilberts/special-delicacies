<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['widget_enabled' => 'boolean', 'accepting_conversations' => 'boolean', 'eligible_role_ids' => 'array', 'automatic_assignment' => 'boolean'];
    }

    public static function current(): self
    {
        // `id` is guarded, so `firstOrCreate(['id' => 1], …)` never inserts the
        // requested id: it auto-increments instead. Once the auto-increment
        // counter moves past 1 (a rolled-back transaction, a deleted settings
        // row, a restored dump), every call created ANOTHER default row and the
        // configured capacity/eligibility settings were silently ignored.
        // Resolve the single settings row by existence, never by a fixed id.
        return static::query()->orderBy('id')->first() ?? static::query()->create([
            'widget_enabled' => true,
            'accepting_conversations' => true,
            'eligible_role_ids' => null,
            'automatic_assignment' => true,
            'max_active_per_agent' => 5,
            'unavailable_message' => 'Our team is away right now. Leave a message and we will get back to you.',
            'fallback_behavior' => 'queue',
        ]);
    }
}
