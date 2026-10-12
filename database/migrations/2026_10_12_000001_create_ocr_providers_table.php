<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_providers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 100)->nullable();
            $table->string('service_hash', 128)->nullable();
            $table->string('bee_path', 255);
            $table->string('uri', 255)->nullable();
            $table->string('status', 20)->default('STOPPED')->index();
            $table->string('error_message', 1000)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_providers');
    }
};
