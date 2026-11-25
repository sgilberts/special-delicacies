<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatAgentPresence extends Model
{
    protected $table = 'chat_agent_presence';
    protected $primaryKey = 'user_id';
    public $incrementing = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }
}
