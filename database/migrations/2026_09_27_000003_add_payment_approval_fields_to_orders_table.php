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
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method')->nullable()->after('total_amount');
            $table->string('payment_proof')->nullable()->after('payment_method');
            $table->text('payment_notes')->nullable()->after('payment_proof');
            $table->text('rejection_reason')->nullable()->after('payment_notes');
            $table->foreignId('approved_by')->nullable()->after('paid_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn([
                'payment_method',
                'payment_proof',
                'payment_notes',
                'rejection_reason',
                'approved_by',
                'approved_at',
            ]);
        });
    }
};
