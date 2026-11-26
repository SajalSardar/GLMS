<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A provisioned physical attendance device (kiosk). `token` stores
     * only a SHA-256 hash of the device's actual token — the plaintext is
     * generated once at creation and returned exactly once in that
     * response (see KioskDeviceController::store()), never persisted or
     * shown again. `is_active` is a separate toggle from soft-delete: a
     * school can pause a device without losing its history.
     */
    public function up(): void
    {
        Schema::create('kiosk_devices', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('created_by');
            $table->nullableMorphs('updated_by');

            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kiosk_devices');
    }
};
