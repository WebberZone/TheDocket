<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // One saved link. The extracted article lives on the same row: a
        // single owner never shares an article between items, so a separate
        // articles table would only add a join to every list query.
        Schema::create('items', function (Blueprint $table) {
            $table->id();

            // The URL as given, and its normalised form (lowercased host,
            // no fragment or tracking parameters, sorted query). The
            // normalised form is the de-duplication key.
            $table->text('url');
            $table->string('normalized_url', 2048)->unique();
            // Where the fetch actually ended up after redirects.
            $table->text('resolved_url')->nullable();

            $table->string('title', 1024)->nullable();
            $table->string('author')->nullable();
            $table->string('site_name')->nullable();
            $table->text('excerpt')->nullable();
            $table->text('lead_image_url')->nullable();
            // Local copy of the lead image on the private disk.
            $table->string('lead_image_path')->nullable();

            // Sanitised article HTML (inline images rewritten to local
            // copies) and its plain-text twin, which feeds the search index.
            $table->longText('content_html')->nullable();
            $table->longText('content_text')->nullable();
            $table->unsignedInteger('word_count')->default(0);
            // Minutes.
            $table->unsignedInteger('reading_time')->default(0);
            $table->string('language', 35)->nullable();
            $table->timestamp('published_at')->nullable();

            // unread | archived
            $table->string('status', 16)->default('unread');
            $table->timestamp('archived_at')->nullable();
            $table->boolean('is_favourite')->default(false);
            $table->timestamp('favourited_at')->nullable();

            // Reader scroll position, 0.0–1.0.
            $table->float('read_progress')->default(0);
            $table->timestamp('last_read_at')->nullable();

            // web | bookmarklet | share | api | pocket
            $table->string('source', 16)->default('web');

            // pending | fetched | failed
            $table->string('fetch_status', 16)->default('pending');
            $table->text('fetch_error')->nullable();
            $table->unsignedInteger('fetch_attempts')->default(0);
            $table->timestamp('fetched_at')->nullable();

            // When the link was saved. Separate from created_at so a Pocket
            // import keeps the original date while created_at records when
            // the row arrived here.
            $table->timestamp('added_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'added_at']);
            $table->index(['is_favourite', 'added_at']);
            $table->index('word_count');
            $table->index('fetch_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
