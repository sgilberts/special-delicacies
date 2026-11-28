<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 5.2 — venue and capacity configuration foundation.
 *
 * Purely additive. No existing table is modified here; the shared
 * `restaurant_tables` record is extended in the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('code', 40)->unique();
            $table->text('description')->nullable();
            // dining_area | private_room | terrace | event_space | other
            $table->string('venue_type', 40)->default('dining_area');
            $table->string('location', 160)->nullable();
            $table->unsignedSmallInteger('max_capacity')->nullable();
            // active | inactive | maintenance
            $table->string('operational_status', 30)->default('active');
            $table->boolean('reservation_enabled')->default(true);
            $table->boolean('exclusive_reservation_enabled')->default(false);
            $table->unsignedSmallInteger('default_reservation_duration_minutes')->default(120);
            $table->unsignedSmallInteger('reservation_slot_minutes')->default(30);
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->index(['operational_status', 'reservation_enabled'], 'venues_status_reservation_index');
        });

        Schema::create('venue_operating_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            // 0 = Sunday … 6 = Saturday (venue-local wall-clock time)
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->unique(['venue_id', 'day_of_week'], 'venue_operating_hours_venue_day_unique');
        });

        Schema::create('venue_special_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            $table->date('date');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->string('reason', 160)->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'date'], 'venue_special_hours_venue_date_unique');
        });

        Schema::create('venue_closures', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('venue_id')->constrained('venues')->cascadeOnDelete();
            // closure | maintenance
            $table->string('closure_type', 30)->default('closure');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['venue_id', 'starts_at'], 'venue_closures_venue_start_index');
        });

        Schema::create('table_unavailability_periods', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['restaurant_table_id', 'starts_at'], 'table_unavailability_table_start_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('table_unavailability_periods');
        Schema::dropIfExists('venue_closures');
        Schema::dropIfExists('venue_special_hours');
        Schema::dropIfExists('venue_operating_hours');
        Schema::dropIfExists('venues');
    }
};
