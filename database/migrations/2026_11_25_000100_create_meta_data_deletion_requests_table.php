<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests Meta sends to our Data Deletion / Deauthorize callbacks. Platform-level (not tenant-owned): one Facebook
     * user can have connected several workspaces. We keep only a hash of the app-scoped user id, never the id itself.
     */
    public function up(): void
    {
        Schema::create('meta_data_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->string('confirmation_code', 32)->unique();
            $table->char('meta_user_hash', 64)->index();
            $table->string('kind', 16); // deletion | deauthorize
            $table->string('status', 16); // pending | completed
            $table->unsignedInteger('connections_affected')->default(0);
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meta_data_deletion_requests');
    }
};
