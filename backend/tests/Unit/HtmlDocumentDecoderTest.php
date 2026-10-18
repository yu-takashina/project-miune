<?php

namespace Tests\Unit;

use App\Services\HtmlDocumentDecoder;
use App\Services\ReadabilityTextExtractor;
use PHPUnit\Framework\TestCase;

class HtmlDocumentDecoderTest extends TestCase
{
    public function test_plain_text_without_a_content_type_is_not_html(): void
    {
        $this->assertNull((new HtmlDocumentDecoder)->decode('alpha', null));
    }

    public function test_a_non_html_content_type_is_rejected(): void
    {
        $this->assertNull((new HtmlDocumentDecoder)->decode('<html><body>本文</body></html>', 'application/pdf'));
    }

    public function test_utf8_html_keeps_the_title(): void
    {
        $decoded = (new HtmlDocumentDecoder)->decode(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>梅雨の手入れ</title></head><body><p>本文</p></body></html>',
            'text/html; charset=UTF-8',
        );

        $this->assertNotNull($decoded);
        $this->assertSame('梅雨の手入れ', $decoded['title']);
        $this->assertStringContainsString('本文', $decoded['html']);
    }

    public function test_shift_jis_html_is_converted_to_utf8(): void
    {
        $html = '<!DOCTYPE html><html><head><meta charset="Shift_JIS"><title>題名</title></head><body><p>本文です</p></body></html>';
        $bytes = mb_convert_encoding($html, 'SJIS-win', 'UTF-8');

        $decoded = (new HtmlDocumentDecoder)->decode($bytes, 'text/html; charset=Shift_JIS');

        $this->assertNotNull($decoded);
        $this->assertSame('題名', $decoded['title']);
        $this->assertStringContainsString('本文です', $decoded['html']);
    }

    public function test_shift_jis_body_is_converted_to_utf8_for_storage(): void
    {
        $body = mb_convert_encoding('保存する本文です。', 'SJIS-win', 'UTF-8');

        $converted = (new HtmlDocumentDecoder)->toUtf8Body($body, 'text/plain; charset=Shift_JIS');

        $this->assertSame('保存する本文です。', $converted);
        $this->assertTrue(mb_check_encoding((string) $converted, 'UTF-8'));
    }

    public function test_a_conflicting_meta_charset_is_rewritten_to_utf8(): void
    {
        $html = '<html><head><meta charset="Shift_JIS"></head><body><p>本文です</p></body></html>';

        $converted = (new HtmlDocumentDecoder)->toUtf8Body($html, 'text/html; charset=UTF-8');

        $this->assertSame('<html><head><meta charset="UTF-8"></head><body><p>本文です</p></body></html>', $converted);
    }

    public function test_a_utf8_meta_charset_is_kept_as_is(): void
    {
        $html = '<html><head><meta charset="utf-8"></head><body><p>本文です</p></body></html>';

        $this->assertSame($html, (new HtmlDocumentDecoder)->toUtf8Body($html, 'text/html; charset=UTF-8'));
    }

    public function test_invalid_utf8_html_fails(): void
    {
        $this->assertNull((new HtmlDocumentDecoder)->decode("\xFF\xFE<html>", 'text/html; charset=UTF-8'));
    }

    public function test_readability_returns_the_article_text(): void
    {
        $paragraph = '観葉植物は梅雨の間、土の表面が乾いてから水をやり、受け皿に残った水は朝に捨てる。';
        $html = '<!DOCTYPE html><html><head><title>梅雨の手入れ</title></head><body>'
            .'<nav>巡回対象外のナビ</nav><article><h1>梅雨の手入れ</h1>'
            .str_repeat('<p>'.$paragraph.'</p>', 12)
            .'</article></body></html>';

        $text = (new ReadabilityTextExtractor)->extract($html);

        $this->assertNotNull($text);
        $this->assertStringContainsString('受け皿に残った水', $text);
        $this->assertStringNotContainsString('巡回対象外のナビ', $text);
    }
}
