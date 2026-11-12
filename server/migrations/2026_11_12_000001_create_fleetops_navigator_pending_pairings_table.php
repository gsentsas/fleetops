<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** S98-R71: pending non-authorizing Navigator v2 device proof and admin approval. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fleetops_navigator_pending_pairings', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('session_id', 64)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('company_uuid', 36);
            $table->string('issuer_user_uuid', 36);
            $table->text('public_key_pem');
            $table->string('key_fingerprint', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('proof_nonce', 64)->charset('ascii')->collation('ascii_bin');
            $table->unsignedBigInteger('proof_expires_unix');
            $table->unsignedBigInteger('expires_unix');
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->string('claim_nonce', 64)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->unsignedBigInteger('claim_expires_unix')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->timestamps();
            $table->index(['company_uuid', 'issuer_user_uuid'], 'nav_v2_pending_issuer_idx');
            $table->index(['expires_unix', 'claimed_at'], 'nav_v2_pending_expire_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_navigator_pending_pairings');
    }
};
