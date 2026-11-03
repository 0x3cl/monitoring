<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('transport')->default('https');
            $table->string('package_name')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_available')->default(false);
            $table->timestampsTz();
        });

        Schema::create('integration_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('integration_type_id')->constrained()->cascadeOnDelete();
            $table->string('version');
            $table->boolean('is_current')->default(false);
            $table->timestampTz('released_at')->nullable();
            $table->text('changelog')->nullable();
            $table->timestampsTz();

            $table->unique(['integration_type_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_versions');
        Schema::dropIfExists('integration_types');
    }
};
