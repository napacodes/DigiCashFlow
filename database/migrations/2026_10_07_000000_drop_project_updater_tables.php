<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('project_updates');
        Schema::dropIfExists('project_licenses');
    }

    public function down(): void
    {
        Schema::create('project_licenses', function (Blueprint $table) {
            $table->id();
            $table->string('product_slug');
            $table->string('item_id')->nullable();
            $table->text('purchase_code')->nullable();
            $table->string('license_token')->nullable();
            $table->string('buyer_username')->nullable();
            $table->string('domain')->nullable();
            $table->string('status')->default('inactive');
            $table->timestamp('support_until')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['product_slug', 'domain']);
            $table->index('product_slug');
            $table->index('item_id');
            $table->index('status');
        });

        Schema::create('project_updates', function (Blueprint $table) {
            $table->id();
            $table->string('version');
            $table->string('channel')->default('stable');
            $table->string('status')->default('available');
            $table->text('package_url')->nullable();
            $table->string('checksum')->nullable();
            $table->text('signature')->nullable();
            $table->json('changelog')->nullable();
            $table->json('requirements')->nullable();
            $table->string('package_path')->nullable();
            $table->string('backup_path')->nullable();
            $table->timestamp('release_date')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->longText('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['version', 'channel']);
            $table->index('version');
            $table->index('channel');
            $table->index('status');
        });
    }
};
