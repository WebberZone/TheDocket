<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        // Tags are matched case-insensitively through the slug, so
        // "Laravel" and "laravel" are the same tag; the name keeps the
        // spelling it was first created with.
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->timestamps();
        });

        Schema::create('item_tag', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();

            $table->primary(['item_id', 'tag_id']);
            $table->index('tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_tag');
        Schema::dropIfExists('tags');
    }
};
