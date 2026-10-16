<?php

namespace App\Services;

use App\Enums\CrawlErrorKind;
use App\Models\CrawlErrorLog;
use App\Models\SitemapPage;
use App\Support\RetryBackoff;
use Illuminate\Support\Facades\DB;
use JsonException;

class EmbedSitemapPage
{
    public const SEGMENT_COUNT = 1;

    public function __construct(private OpenAiEmbeddings $embeddings = new OpenAiEmbeddings) {}

    public function apply(SitemapPage $page): void
    {
        $hash = $page->article_text_sha256;

        if ($hash === null) {
            $page->embeddings()->delete();

            return;
        }

        if ($page->embedding_failed_sha256 === $hash || $this->alreadyEmbedded($page, $hash)) {
            return;
        }

        $text = $page->article_text;

        if (! is_string($text) || $text === '') {
            $page->embeddings()->delete();

            return;
        }

        try {
            $vector = $this->embeddings->embed($text);
        } catch (OpenAiEmbeddingException $exception) {
            if ($exception->authentication) {
                throw $exception;
            }

            CrawlErrorLog::forPage($page, $exception->getMessage(), null, CrawlErrorKind::Embedding);
            $page->embedding_error = $exception->getMessage();
            $page->embedding_failed_sha256 = $exception->permanent ? $hash : null;

            if ($exception->permanent) {
                $page->embedding_failure_count = 0;
                $page->embedding_retry_at = null;
            } else {
                $page->embedding_failure_count++;
                $page->embedding_retry_at = RetryBackoff::nextAt($page->embedding_failure_count);
            }

            $page->save();

            return;
        }

        DB::transaction(function () use ($page, $vector, $hash): void {
            $page->embeddings()->delete();
            $this->storeVector($page, $vector, $hash);
            $page->embedding_error = null;
            $page->embedding_failed_sha256 = null;
            $page->embedding_failure_count = 0;
            $page->embedding_retry_at = null;
            $page->save();
        });
    }

    private function alreadyEmbedded(SitemapPage $page, string $hash): bool
    {
        $total = $page->embeddings()->count();
        $matching = $page->embeddings()->where('article_text_sha256', $hash)->count();

        return $total === self::SEGMENT_COUNT && $matching === self::SEGMENT_COUNT;
    }

    /**
     * @param  list<float>  $vector
     */
    private function storeVector(SitemapPage $page, array $vector, string $hash): void
    {
        try {
            $encoded = json_encode($vector, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenAiEmbeddingException('埋め込みを保存できません。', false, false, $exception);
        }

        $embeddedAt = now()->format('Y-m-d H:i:s');

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('sitemap_page_embeddings')->insert([
                'sitemap_page_id' => $page->id,
                'embedding' => $encoded,
                'article_text_sha256' => $hash,
                'embedded_at' => $embeddedAt,
            ]);

            return;
        }

        DB::insert(
            'insert into sitemap_page_embeddings (sitemap_page_id, embedding, article_text_sha256, embedded_at) values (?, VEC_FromText(?), ?, ?)',
            [$page->id, $encoded, $hash, $embeddedAt],
        );
    }
}
