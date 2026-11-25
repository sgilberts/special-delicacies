<?php

namespace Tests\Feature;

use App\Models\RestaurantSetting;
use Database\Seeders\RestaurantSettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestaurantSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_seed_and_retrieve_settings(): void
    {
        $this->seed(RestaurantSettingSeeder::class);

        $name = RestaurantSetting::getValue('restaurant_name');
        $this->assertEquals('Special Delicacies', $name);

        $paymentMethod = RestaurantSetting::getValue('default_payment_method');
        $this->assertEquals('pay_on_delivery', $paymentMethod);

        // Verify approved Ghana Cedi currency configuration
        $currency = RestaurantSetting::getValue('currency');
        $currencySymbol = RestaurantSetting::getValue('currency_symbol');

        $this->assertEquals('GHS', $currency);
        $this->assertEquals('GH₵', $currencySymbol);
    }

    public function test_can_set_and_update_setting(): void
    {
        RestaurantSetting::setValue('custom_key', 'custom_value', 'custom_group');
        $this->assertEquals('custom_value', RestaurantSetting::getValue('custom_key'));

        RestaurantSetting::setValue('custom_key', 'updated_value', 'custom_group');
        $this->assertEquals('updated_value', RestaurantSetting::getValue('custom_key'));

        $this->assertDatabaseCount('restaurant_settings', 1);
    }

    public function test_returns_default_when_setting_not_found(): void
    {
        $value = RestaurantSetting::getValue('non_existent_key', 'default_fallback');
        $this->assertEquals('default_fallback', $value);
    }
}
