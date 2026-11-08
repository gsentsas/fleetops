<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('telematic_webhook_receipts', function (Blueprint $table): void {
            // Lowercase hex digests/tokens compared byte-for-byte: on MySQL the
            // default utf8mb4_unicode_ci collation is case-insensitive (a
            // re-cased claim token would match). SQLite ignores these modifiers
            // and already compares with BINARY.
            $table->string('scope_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('receipt_key', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('payload_hash', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('claim_token', 64)->charset('ascii')->collation('ascii_bin');
            $table->string('status', 16);
            // DATETIME, not TIMESTAMP: the ledger writes UTC literals and
            // compares them; a MySQL TIMESTAMP is converted through the session
            // time zone (a DST-gap literal such as 2026-03-29 02:30:00 comes
            // back as 03:00:00 under Europe/* SYSTEM time zones).
            $table->dateTime('lease_expires_at');
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
            $table->primary(['scope_hash', 'receipt_key'], 'telematic_receipt_scope_key_pk');
            $table->index(['status', 'lease_expires_at'], 'telematic_receipt_reconcile_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telematic_webhook_receipts');
    }
};
