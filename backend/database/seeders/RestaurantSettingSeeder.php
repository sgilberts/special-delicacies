<?php

namespace Database\Seeders;

use App\Models\RestaurantSetting;
use Illuminate\Database\Seeder;

class RestaurantSettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'restaurant_name', 'value' => 'Special Delicacies', 'group' => 'general'],
            ['key' => 'restaurant_phone', 'value' => '+233 30 123 4567', 'group' => 'general'],
            ['key' => 'restaurant_email', 'value' => 'contact@special-delicacies.local', 'group' => 'general'],
            ['key' => 'restaurant_address', 'value' => '100 Gourmet Boulevard, Culinary Quarter', 'group' => 'general'],
            ['key' => 'currency', 'value' => 'GHS', 'group' => 'general'],
            ['key' => 'currency_symbol', 'value' => 'GH₵', 'group' => 'general'],
            ['key' => 'timezone', 'value' => 'Africa/Accra', 'group' => 'general'],
            ['key' => 'operating_hours', 'value' => '11:00 - 22:00', 'group' => 'operations'],
            ['key' => 'dine_in_enabled', 'value' => 'true', 'group' => 'operations'],
            ['key' => 'delivery_enabled', 'value' => 'true', 'group' => 'operations'],
            ['key' => 'default_payment_method', 'value' => 'pay_on_delivery', 'group' => 'payment'],
        ];

        foreach ($settings as $setting) {
            RestaurantSetting::updateOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value'], 'group' => $setting['group']]
            );
        }
    }
}
