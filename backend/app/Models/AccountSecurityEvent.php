<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['customer_id', 'user_id', 'event', 'channel', 'ip_address', 'user_agent', 'created_at'])]
class AccountSecurityEvent extends Model
{
    public $timestamps = false;
}
