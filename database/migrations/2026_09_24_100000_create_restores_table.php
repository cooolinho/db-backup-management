<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->nullable()->constrained('backups')->nullOnDelete();
            /** restore | swap_back */
            $table->string('kind')->default('restore');
            /** Local path of the backup/upload being restored, or the archive database name for swap_back. */
            $table->string('source_archive')->nullable();
            $table->string('database');
            $table->string('tmp_database')->nullable();
            $table->string('archive_name')->nullable();
            /** pending | running | success | failed */
            $table->string('status')->default('pending');
            $table->string('step')->nullable();
            $table->longText('log')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['database', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restores');
    }
};
