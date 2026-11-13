<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'device_push_tokens';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('platform', 32)->default('unknown'); // android|ios|web|unknown
            $table->string('device_id', 191)->nullable();
            $table->string('app_version', 64)->nullable();
            $table->string('locale', 16)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->unique('token');
            $table->index(['user_id', 'platform']);
            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
