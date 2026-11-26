<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['public_id', 'customer_id', 'user_id', 'purpose', 'channel', 'recipient_hash', 'secret_hash', 'expires_at', 'sent_at', 'consumed_at', 'failed_attempts'])]
class AccountSecurityChallenge extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'sent_at' => 'datetime', 'consumed_at' => 'datetime'];
    }
}
