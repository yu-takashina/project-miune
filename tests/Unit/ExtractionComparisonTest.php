<?php

namespace Tests\Unit;

use App\Services\ExtractionComparison;
use PHPUnit\Framework\TestCase;

class ExtractionComparisonTest extends TestCase
{
    public function test_empty_texts_match(): void
    {
        $this->assertTrue((new ExtractionComparison)->matches("  \n", ''));
    }

    public function test_one_empty_text_is_a_disagreement(): void
    {
        $this->assertFalse((new ExtractionComparison)->matches('観葉植物は土が乾いてから水をやる。', ''));
    }

    public function test_whitespace_differences_still_match(): void
    {
        $this->assertTrue((new ExtractionComparison)->matches("土が乾いてから\n水をやる。", '土が乾いてから 水をやる。'));
    }

    public function test_short_texts_match_only_when_equal(): void
    {
        $comparison = new ExtractionComparison;

        $this->assertTrue($comparison->matches('葉水', '葉水'));
        $this->assertFalse($comparison->matches('葉水', '植え'));
    }

    public function test_different_texts_disagree(): void
    {
        $this->assertFalse((new ExtractionComparison)->matches(
            '観葉植物は土の表面が乾いてから水をやる。',
            'パンの発酵は室温で一次発酵させる。',
        ));
    }

    public function test_a_small_addition_still_matches(): void
    {
        $base = '観葉植物は梅雨の間、土の表面が乾いてから水をやり、受け皿に残った水は捨てる。葉のホコリは湿った布で拭く。';

        $this->assertTrue((new ExtractionComparison)->matches($base, $base.'風通しの良い場所に置く。'));
    }
}
