<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transaction_id')->unique()->nullable();  // ID coming from FedaPay
            $table->foreignUuid('client_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('prestataire_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('XOF');
            $table->string('description')->nullable();
            $table->string('payment_method')->nullable()->default('mobile_money');
            $table->decimal('commission', 10, 2)->nullable();  // Platform fee withheld at release
            $table->string('payout_id')->nullable();  // ID of the FedaPay payout, set before the funds are sent
            $table->string('status')->index();  // see Safepay\Enums\TransactionStatus
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
