<?php

namespace App\Services;

use fivefilters\Readability\ParseException;
use fivefilters\Readability\Readability;
use Throwable;

class ReadabilityTextExtractor
{
    public function extract(string $html): ?string
    {
        if (trim($html) === '') {
            return '';
        }

        try {
            $article = (new Readability)->parse($html);
        } catch (ParseException) {
            return null;
        } catch (Throwable) {
            return null;
        }

        if (! $article->hasContent()) {
            return '';
        }

        $content = $article->content;

        if ($content === null || $content === '') {
            return '';
        }

        return $this->toPlainText($content);
    }

    private function toPlainText(string $html): string
    {
        $withBreaks = preg_replace('/<br\s*\/?>/i', "\n", $html) ?? $html;
        $withBreaks = preg_replace('/<\/(?:p|div|h[1-6]|li|tr|blockquote|section|article|figcaption)>/i', "\n", $withBreaks) ?? $withBreaks;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
