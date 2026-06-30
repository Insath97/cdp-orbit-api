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
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone_primary')->nullable();
            $table->string('phone_secondary')->nullable();
            $table->boolean('have_whatsapp')->default(false);
            $table->string('whatsapp_number')->nullable();
            $table->date('birthday')->nullable();
            $table->string('id_type')->nullable();
            $table->string('id_number')->nullable();
            $table->enum('preferred_language', ['english', 'sinhala', 'tamil'])->default('english');
            $table->string('company')->nullable();
            $table->decimal('value', 10, 2)->nullable();
            $table->string('source')->nullable();
            $table->text('notes')->nullable();
            
            $table->foreignId('status_id')->constrained('statuses')->onDelete('restrict');
            $table->foreignId('group_id')->nullable()->constrained('groups')->onDelete('set null');
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
