<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customer_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('title');
            $table->string('visit_type')->default('collection'); // collection, negotiation, survey_installation, maintenance
            $table->dateTime('scheduled_at');
            $table->string('status')->default('scheduled'); // scheduled, completed, rescheduled, cancelled
            
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->text('result_notes')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            $table->index(['merchant_id', 'status']);
            $table->index(['merchant_id', 'scheduled_at']);
            $table->index(['customer_id']);
            $table->index(['assigned_staff_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_visits');
    }
};
