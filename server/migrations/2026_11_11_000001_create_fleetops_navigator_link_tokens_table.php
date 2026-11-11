<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S86: persistent, tenant-bound Navigator one-time link state.
 *
 * Only the SHA-256 fingerprint of the opaque random token is persisted.
 * A successful redemption consumes the row under a database transaction.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleetops_navigator_link_tokens', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('token_hash', 64)->charset('ascii')->collation('ascii_bin')->unique('fleetops_nav_link_hash_unique');
            $table->string('company_uuid', 36);
            $table->string('issuer_user_uuid', 36);
            $table->dateTime('expires_at');
            $table->dateTime('consumed_at')->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'issuer_user_uuid'], 'fleetops_nav_link_issuer_idx');
            $table->index(['expires_at', 'consumed_at'], 'fleetops_nav_link_lifecycle_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_navigator_link_tokens');
    }
};
