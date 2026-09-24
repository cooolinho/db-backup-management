<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            /** e.g. backup.created, restore.requested, archive.swapped, settings.updated, auth.login - see App\Support\Audit. */
            $table->string('action');
            /** Not a true polymorphic relation: a subject may be a model (Backup/Restore) or a plain identifier (an archive database name). */
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->string('description')->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
