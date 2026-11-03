<?php

use App\Enums\ApplicationStatus;
use App\Enums\ConnectionStatus;
use App\Enums\Environment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->string('app_code', 32)->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('environment')->default(Environment::Production->value);
            $table->foreignId('server_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_type_id')->constrained();
            $table->foreignId('integration_version_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default(ApplicationStatus::PendingInstallation->value);
            $table->string('connection_status')->default(ConnectionStatus::NeverConnected->value);
            $table->string('base_url')->nullable();
            $table->string('health_endpoint')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->jsonb('monitoring_config')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['environment', 'status']);
            $table->index('connection_status');
        });

        // Application codes (APP-00001) are drawn from a dedicated sequence so
        // concurrent registrations can never collide on the same code.
        //
        // The sequence is tied to the table it serves, so dropping the table
        // (migrate:fresh) takes the sequence with it instead of leaving a
        // stray object behind that would resume mid-count on the next migrate.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE SEQUENCE IF NOT EXISTS application_code_seq START WITH 1 INCREMENT BY 1');
            DB::statement('ALTER SEQUENCE application_code_seq OWNED BY applications.id');
            DB::statement('ALTER SEQUENCE application_code_seq RESTART WITH 1');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP SEQUENCE IF EXISTS application_code_seq');
        }

        Schema::dropIfExists('applications');
    }
};
