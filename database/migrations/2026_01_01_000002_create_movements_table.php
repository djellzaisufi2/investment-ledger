<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 18, 2);
            $table->string('instrument')->nullable();
            $table->unsignedInteger('quantity')->nullable();
            $table->decimal('price_per_unit', 18, 4)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['client_id', 'type']);
            $table->index(['client_id', 'instrument']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('movements');
    }
};
