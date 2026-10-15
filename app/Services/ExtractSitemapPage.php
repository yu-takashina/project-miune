<?php

namespace App\Services;

use App\Enums\ExtractionStatus;
use App\Models\SitemapPage;
use Illuminate\Support\Carbon;
use Throwable;

class ExtractSitemapPage
{
    public function __construct(
        private HtmlDocumentDecoder $decoder = new HtmlDocumentDecoder,
        private ReadabilityTextExtractor $readability = new ReadabilityTextExtractor,
        private TrafilaturaTextExtractor $trafilatura = new TrafilaturaTextExtractor,
        private ExtractionComparison $comparison = new ExtractionComparison,
    ) {}

    public function apply(SitemapPage $page, ?string $contentType): void
    {
        $body = $page->body;

        if ($body === null) {
            $this->store($page, null, null, null);

            return;
        }

        $document = $this->decoder->decode($body, $contentType);

        if ($document === null) {
            $this->store($page, null, ExtractionStatus::Failed, now());

            return;
        }

        $readability = $this->readability->extract($document['html']);
        $trafilatura = $this->trafilatura->extract($document['html']);
        $readabilityText = is_string($readability) ? $this->stripLeadingTitle($readability, $document['title']) : null;
        $trafilaturaText = is_string($trafilatura) ? $this->stripLeadingTitle($trafilatura, $document['title']) : null;
        $articleText = $this->adoptedText($readabilityText, $trafilaturaText);

        $this->store($page, $articleText, $this->status($articleText), now());
    }

    /**
     * 保存する本文。抽出器ごとの文字列は保存しない。null は両方の抽出器が異常終了したとき。
     *
     * 片方だけ異常終了したとき、または片方だけ本文を取れたときは、取れた側をそのまま使う。
     *
     * 不一致の分岐は、将来 ChatGPT に両方の本文を渡すために残している。このプロジェクトはデモのため、課金を避け、その分岐では Trafilatura が抽出した文字列を返す。一致しているときも差は小さいので、同じ本文として Trafilatura を返す。
     */
    public function adoptedText(?string $readabilityText, ?string $trafilaturaText): ?string
    {
        if ($readabilityText === null || $trafilaturaText === null) {
            return $trafilaturaText ?? $readabilityText;
        }

        if ($readabilityText === '' || $trafilaturaText === '') {
            return $trafilaturaText !== '' ? $trafilaturaText : $readabilityText;
        }

        if ($this->comparison->matches($readabilityText, $trafilaturaText)) {
            return $trafilaturaText;
        }

        return $trafilaturaText;
    }

    private function status(?string $articleText): ?ExtractionStatus
    {
        if ($articleText === null) {
            return ExtractionStatus::Failed;
        }

        if ($articleText === '') {
            return ExtractionStatus::Empty;
        }

        return null;
    }

    private function stripLeadingTitle(string $text, string $title): string
    {
        $normalizedTitle = $this->collapse($title);

        if ($normalizedTitle === '') {
            return trim($text);
        }

        $lines = preg_split("/\R/u", $text, 2);

        if ($lines === false || ! isset($lines[0]) || $this->collapse($lines[0]) !== $normalizedTitle) {
            return trim($text);
        }

        return trim($lines[1] ?? '');
    }

    private function collapse(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim(is_string($collapsed) ? $collapsed : $text);
    }

    private function store(
        SitemapPage $page,
        ?string $articleText,
        ?ExtractionStatus $status,
        ?Carbon $extractedAt,
    ): void {
        $previous = $page->articleHashRemembered
            ? $page->articleHashAtFetch
            : $page->article_text_sha256;

        $page->update([
            'article_text' => $articleText,
            'extraction_status' => $status,
            'extracted_at' => $extractedAt,
        ]);

        $current = $page->article_text_sha256;

        if ($current === null || ($current === $previous && $page->hasEmbeddingForArticle())) {
            return;
        }

        try {
            (new EmbedSitemapPage)->apply($page);
        } catch (Throwable) {
            return;
        }
    }
}
