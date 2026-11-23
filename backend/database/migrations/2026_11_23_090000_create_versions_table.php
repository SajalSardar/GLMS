<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The national catalog of curriculum versions. Fixed at exactly two
     * rows by design — see docs/superpowers/specs/2026-11-23-national-
     * version-catalog-design.md. Seeded here, not in a seeder, so the
     * fixed-set invariant holds in every environment including a fresh
     * RefreshDatabase test run. created_by_id/created_by_type/
     * updated_by_id/updated_by_type match the polymorphic shape every
     * other audited table in this app ended up with after
     * 2026_11_12_100300_make_created_by_updated_by_polymorphic.php —
     * declared directly here since this table is new, not retrofitted.
     */
    public function up(): void
    {
        Schema::create('versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->string('created_by_type')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->string('updated_by_type')->nullable();
            $table->string('name')->unique();
            $table->string('bn_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('versions')->insert([
            ['name' => 'Bangla', 'bn_name' => 'বাংলা', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'English', 'bn_name' => 'ইংরেজি', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('versions');
    }
};
