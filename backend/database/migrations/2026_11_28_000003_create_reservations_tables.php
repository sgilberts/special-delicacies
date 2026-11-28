<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 5.1 — reservation domain foundation.
 *
 * Records the reservation header, its table allocations (one row per physical
 * `restaurant_tables` record, so multiple tables are separate linked rows) and
 * an auditable status history. No availability, lifecycle, deposit calculation
 * or payment behaviour is implemented here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();

            // Existing customer and organisation architecture is reused.
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignId('organisation_id')->nullable()->constrained('organisations')->restrictOnDelete();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->restrictOnDelete();

            // single_table | multiple_tables | exclusive_venue
            $table->string('category', 30);
            $table->date('reservation_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('guest_count');
            $table->unsignedSmallInteger('requested_table_count')->default(1);
            $table->string('status', 30)->default('requested');

            // Booking value is authoritative on the commercial document; the
            // snapshot columns below only preserve what was agreed at booking
            // time for later audit and reporting.
            $table->foreignId('commercial_document_id')->nullable()->constrained('commercial_documents')->restrictOnDelete();
            $table->decimal('booking_value', 12, 2)->nullable();
            $table->string('booking_currency', 3)->nullable();

            // Deposit configuration snapshot — captured, never calculated, in
            // these stages. The authoritative policy will live in the Stage 5.5
            // deposit settings when that stage is authorised.
            $table->boolean('deposit_required')->default(false);
            $table->decimal('deposit_percentage', 5, 2)->nullable();
            $table->decimal('deposit_amount', 12, 2)->nullable();
            $table->string('deposit_currency', 3)->nullable();
            $table->json('deposit_policy_snapshot')->nullable();

            $table->text('special_instructions')->nullable();
            $table->text('internal_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reservation_date', 'status'], 'reservations_date_status_index');
            $table->index(['venue_id', 'reservation_date'], 'reservations_venue_date_index');
            $table->index(['customer_id', 'reservation_date'], 'reservations_customer_date_index');
            $table->index(['organisation_id', 'reservation_date'], 'reservations_organisation_date_index');
        });

        Schema::create('reservation_table_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            // The shared physical table record — never a duplicated registry.
            $table->foreignId('restaurant_table_id')->constrained('restaurant_tables')->restrictOnDelete();
            $table->dateTime('occupancy_start');
            $table->dateTime('occupancy_end');
            $table->dateTime('released_at')->nullable();
            $table->timestamps();

            $table->unique(['reservation_id', 'restaurant_table_id', 'occupancy_start'], 'reservation_allocations_unique');
            $table->index(['restaurant_table_id', 'occupancy_start', 'occupancy_end'], 'reservation_allocations_table_period_index');
        });

        Schema::create('reservation_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reservation_id')->constrained('reservations')->cascadeOnDelete();
            $table->string('previous_status', 30)->nullable();
            $table->string('new_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['reservation_id', 'created_at'], 'reservation_status_histories_reservation_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_histories');
        Schema::dropIfExists('reservation_table_allocations');
        Schema::dropIfExists('reservations');
    }
};
