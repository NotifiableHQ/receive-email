<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Notifiable\ReceiveEmail\Models\Sender;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(Config::string('receive_email.email-table'), function (Blueprint $table) {
            $table->ulid()->primary();
            $table->string('message_id')->unique();
            $table->foreignIdFor(Sender::class)->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamp('sent_at');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Config::string('receive_email.email-table'));
    }
};
