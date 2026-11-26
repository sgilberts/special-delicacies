<?php

namespace App\Contracts;

interface SmsOtpDelivery
{
    public function send(string $phone, string $message): void;
}
