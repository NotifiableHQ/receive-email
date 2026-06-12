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
            $table->string('envelope_sender')->nullable();
            $table->json('envelope_recipients');
            $table->string('client_address')->nullable();
            $table->string('queue_id')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->foreignIdFor(Sender::class)->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('parsed_at')->nullable();
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
