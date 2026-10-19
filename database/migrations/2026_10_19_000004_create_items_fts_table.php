<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class() extends Migration
{
    /**
     * Full-text search over items, using SQLite FTS5 as an external-content
     * table: the text lives once, in `items`, and the index stores only the
     * tokens. Triggers keep it in step with every insert, update and
     * delete, including bulk query-builder writes that skip model events.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE VIRTUAL TABLE items_fts USING fts5(
                title,
                site_name,
                content_text,
                content='items',
                content_rowid='id',
                tokenize='unicode61 remove_diacritics 2'
            )
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER items_fts_after_insert AFTER INSERT ON items BEGIN
                INSERT INTO items_fts (rowid, title, site_name, content_text)
                VALUES (new.id, new.title, new.site_name, new.content_text);
            END
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER items_fts_after_delete AFTER DELETE ON items BEGIN
                INSERT INTO items_fts (items_fts, rowid, title, site_name, content_text)
                VALUES ('delete', old.id, old.title, old.site_name, old.content_text);
            END
        SQL);

        // Only the indexed columns: scroll-progress saves and status changes
        // happen constantly and must not churn the index.
        DB::statement(<<<'SQL'
            CREATE TRIGGER items_fts_after_update AFTER UPDATE OF title, site_name, content_text ON items BEGIN
                INSERT INTO items_fts (items_fts, rowid, title, site_name, content_text)
                VALUES ('delete', old.id, old.title, old.site_name, old.content_text);
                INSERT INTO items_fts (rowid, title, site_name, content_text)
                VALUES (new.id, new.title, new.site_name, new.content_text);
            END
        SQL);

        // Index anything already in the table (a no-op on a fresh install).
        DB::statement("INSERT INTO items_fts (items_fts) VALUES ('rebuild')");
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS items_fts_after_update');
        DB::statement('DROP TRIGGER IF EXISTS items_fts_after_delete');
        DB::statement('DROP TRIGGER IF EXISTS items_fts_after_insert');
        DB::statement('DROP TABLE IF EXISTS items_fts');
    }
};
