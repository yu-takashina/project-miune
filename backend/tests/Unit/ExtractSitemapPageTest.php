<?php

namespace Tests\Unit;

use App\Services\ExtractSitemapPage;
use PHPUnit\Framework\TestCase;

class ExtractSitemapPageTest extends TestCase
{
    public function test_a_disagreement_returns_the_trafilatura_text(): void
    {
        $this->assertSame(
            'パンの発酵は室温で一次発酵させる。',
            (new ExtractSitemapPage)->adoptedText('観葉植物は土の表面が乾いてから水をやる。', 'パンの発酵は室温で一次発酵させる。'),
        );
    }

    public function test_a_match_returns_the_trafilatura_text(): void
    {
        $this->assertSame('同じ本文', (new ExtractSitemapPage)->adoptedText('同じ本文', '同じ本文'));
    }

    public function test_the_readability_text_is_used_when_trafilatura_fails(): void
    {
        $this->assertSame('Readabilityの本文', (new ExtractSitemapPage)->adoptedText('Readabilityの本文', null));
    }

    public function test_the_trafilatura_text_is_used_when_readability_fails(): void
    {
        $this->assertSame('Trafilaturaの本文', (new ExtractSitemapPage)->adoptedText(null, 'Trafilaturaの本文'));
    }

    public function test_the_non_empty_text_is_used_when_only_one_extractor_finds_content(): void
    {
        $extractor = new ExtractSitemapPage;

        $this->assertSame('Readabilityの本文', $extractor->adoptedText('Readabilityの本文', ''));
        $this->assertSame('Trafilaturaの本文', $extractor->adoptedText('', 'Trafilaturaの本文'));
    }

    public function test_empty_text_is_adopted_when_neither_extractor_finds_content(): void
    {
        $this->assertSame('', (new ExtractSitemapPage)->adoptedText('', ''));
    }

    public function test_nothing_is_adopted_when_both_fail(): void
    {
        $this->assertNull((new ExtractSitemapPage)->adoptedText(null, null));
    }
}
