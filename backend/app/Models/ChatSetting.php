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
        return static::query()->firstOrCreate(['id' => 1], [
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
