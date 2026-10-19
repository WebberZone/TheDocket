<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // DB-backed overrides for the editable knobs in config/docket.php.
        // One row per dotted key (e.g. "receipts.max_kb"); value is JSON so
        // arrays (currencies, ocr.backoff) and scalars share one column.
        // Read through App\Services\Settings, which falls back to the
        // config default when no row exists.
        Schema::create(
            'settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
