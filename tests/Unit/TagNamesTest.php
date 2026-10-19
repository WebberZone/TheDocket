<?php

namespace Tests\Unit;

use App\Models\Item;
use App\Models\Tag;
use PHPUnit\Framework\TestCase;

class TagNamesTest extends TestCase
{
    public function test_tag_lists_are_split_cleaned_and_deduplicated(): void
    {
        $this->assertSame(['Laravel', 'php', 'long reads'], Tag::parseList(' Laravel, php,,LARAVEL ,  long   reads '));
        $this->assertSame(['one', 'two'], Tag::parseList(['one', ' two ', '', 'ONE', ['nested']]));
        $this->assertSame([], Tag::parseList(null));
        $this->assertSame([], Tag::parseList(''));
    }

    public function test_slugs_ignore_case_but_keep_non_latin_scripts(): void
    {
        $this->assertSame('long-reads', Tag::slugFor('Long Reads'));
        $this->assertSame('日本語', Tag::slugFor('日本語'));
        $this->assertSame('ελληνικά', Tag::slugFor('ΕΛΛΗΝΙΚΆ'));
    }

    public function test_pipes_cannot_sneak_into_a_name(): void
    {
        $this->assertSame('a b', Tag::cleanName('a|b'));
    }

    public function test_search_input_becomes_a_safe_fts_query(): void
    {
        $this->assertSame('"slow"* "reading"*', Item::ftsQuery('  slow   reading '));
        $this->assertSame('"c++"* "AND"*', Item::ftsQuery('c++ AND'));
        $this->assertSame('"experts"*', Item::ftsQuery('"experts"'));
        $this->assertNull(Item::ftsQuery('   '));
        $this->assertNull(Item::ftsQuery('""'));
    }
}
