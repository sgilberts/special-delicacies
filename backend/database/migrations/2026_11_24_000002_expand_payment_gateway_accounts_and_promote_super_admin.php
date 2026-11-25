<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropUnique('payment_gateways_provider_unique');
            $table->index('provider', 'payment_gateways_provider_index');
            $table->json('configuration')->nullable()->after('secret_key');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('payment_gateway_id')->nullable()->after('gateway_checkout_url')
                ->constrained('payment_gateways')->nullOnDelete();
        });

        $legacy = Role::where('slug', 'administrator')->first();
        $primary = Role::where('slug', 'super-admin')->first();

        if ($legacy && $primary) {
            $primary->users()->syncWithoutDetaching($legacy->users()->pluck('users.id')->all());
            $primary->permissions()->syncWithoutDetaching($legacy->permissions()->pluck('permissions.id')->all());
            $legacy->delete();
        } elseif ($legacy) {
            $legacy->slug = 'super-admin';
            $legacy->name = 'Super Admin';
            $legacy->description = 'Primary system owner with full access to every management, operational, settings, and audit capability.';
            $legacy->save();
        }

        Role::where('slug', 'super-admin')->update([
            'name' => 'Super Admin',
            'description' => 'Primary system owner with full access to every management, operational, settings, and audit capability.',
        ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payment_gateway_id']);
            $table->dropColumn('payment_gateway_id');
        });

        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropIndex('payment_gateways_provider_index');
            $table->dropColumn('configuration');
        });

        Role::where('slug', 'super-admin')->update([
            'slug' => 'administrator',
            'name' => 'Administrator',
            'description' => 'Full administrative access to restaurant operations, settings, menu, and staff.',
        ]);
    }
};
