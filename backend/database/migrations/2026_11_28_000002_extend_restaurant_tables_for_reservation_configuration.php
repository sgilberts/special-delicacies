<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * D-5A — the existing `restaurant_tables` record stays the single physical
 * table registry for dine-in QR ordering and reservations.
 *
 * Only additive nullable/defaulted columns are introduced. Existing table
 * numbers, capacities, `is_active` semantics, QR codes and order links are
 * untouched, so every existing dine-in operation keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            $table->foreignId('venue_id')->nullable()->after('id')->constrained('venues')->restrictOnDelete();
            // standard | booth | bar | private | outdoor | other
            $table->string('table_type', 40)->nullable()->after('capacity');
            // available | maintenance | out_of_service — reservation-side
            // operational state. Distinct from `is_active`, which continues to
            // control whether the table is offered in QR/dine-in ordering.
            $table->string('operational_status', 30)->default('available')->after('is_active');
            $table->boolean('reservation_eligible')->default(true)->after('operational_status');
            $table->json('seating_configuration')->nullable()->after('reservation_eligible');
            $table->text('admin_notes')->nullable()->after('seating_configuration');

            $table->index(['venue_id', 'reservation_eligible'], 'restaurant_tables_venue_reservation_index');
        });
    }

    public function down(): void
    {
        Schema::table('restaurant_tables', function (Blueprint $table): void {
            // MySQL needs the foreign key removed before its supporting
            // composite index can be dropped.
            $table->dropForeign(['venue_id']);
            $table->dropIndex('restaurant_tables_venue_reservation_index');
            $table->dropColumn([
                'venue_id',
                'table_type',
                'operational_status',
                'reservation_eligible',
                'seating_configuration',
                'admin_notes',
            ]);
        });
    }
};
