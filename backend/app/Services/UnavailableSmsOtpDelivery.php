<?php

namespace App\Services;

use App\Contracts\SmsOtpDelivery;
use RuntimeException;

class UnavailableSmsOtpDelivery implements SmsOtpDelivery
{
    public function send(string $phone, string $message): void
    {
        throw new RuntimeException('SMS delivery is not configured.');
    }
}
