<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['provider', 'display_name', 'public_key', 'secret_key', 'webhook_secret', 'configuration', 'is_enabled', 'is_default'])]
class PaymentGateway extends Model
{
    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'configuration' => 'array',
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
