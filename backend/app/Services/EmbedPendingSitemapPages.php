<?php

namespace App\Services;

use App\Enums\CrawlErrorKind;
use App\Models\CrawlErrorLog;
use App\Models\SitemapPage;
use App\Support\RetryBackoff;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * 採用本文のハッシュに合うベクトルが無いページを、時間の許す範囲でベクトル化する。
 */
class EmbedPendingSitemapPages
{
    /** 1分おきの実行とかぶらないよう、1回はこの秒数で打ち切る。 */
    public const BUDGET_SECONDS = 55;

    public const AUTH_FAILURES_KEY = 'embedding-auth:failures';

    public const AUTH_RETRY_AT_KEY = 'embedding-auth:retry-at';

    /** 認証エラーの待ちを覚えておく秒数。最長の待ち時間より長くする。 */
    private const AUTH_CACHE_SECONDS = 2 * 86400;

    public function __construct(
        private EmbedSitemapPage $embedder = new EmbedSitemapPage,
        public int $budgetSeconds = self::BUDGET_SECONDS,
    ) {}

    /**
     * @return int ベクトル化を試したページ数
     */
    public function run(): int
    {
        if ($this->waitingForAuthentication()) {
            return 0;
        }

        $deadline = microtime(true) + $this->budgetSeconds;
        $attempted = [];

        while (microtime(true) < $deadline) {
            $page = $this->nextPage($attempted);

            if (! $page instanceof SitemapPage) {
                break;
            }

            $attempted[] = $page->id;

            try {
                $this->embedder->apply($page);
            } catch (OpenAiEmbeddingException $exception) {
                if ($exception->authentication) {
                    $this->deferForAuthentication($exception->getMessage());

                    return count($attempted);
                }

                $this->deferPage($page, $exception->getMessage());
            } catch (Throwable $exception) {
                $this->deferPage($page, $exception->getMessage());
            }
        }

        if ($attempted !== []) {
            Cache::forget(self::AUTH_FAILURES_KEY);
            Cache::forget(self::AUTH_RETRY_AT_KEY);
        }

        return count($attempted);
    }

    /**
     * @param  list<int>  $exceptIds
     */
    private function nextPage(array $exceptIds): ?SitemapPage
    {
        $now = now();

        $page = SitemapPage::query()
            ->missingEmbedding()
            ->when($exceptIds !== [], function (Builder $query) use ($exceptIds): void {
                $query->whereNotIn('id', $exceptIds);
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('embedding_retry_at')
                    ->orWhere('embedding_retry_at', '<=', $now);
            })
            ->orderBy('id')
            ->first();

        return $page instanceof SitemapPage ? $page : null;
    }

    private function waitingForAuthentication(): bool
    {
        $retryAt = Cache::get(self::AUTH_RETRY_AT_KEY);

        return is_int($retryAt) && $retryAt > now()->getTimestamp();
    }

    /**
     * API キーの誤りはページの問題ではないので、ページではなく処理全体を待たせる。
     */
    private function deferForAuthentication(string $message): void
    {
        $stored = Cache::get(self::AUTH_FAILURES_KEY);
        $failures = (is_int($stored) ? $stored : 0) + 1;
        $retryAt = RetryBackoff::nextAt($failures);

        CrawlErrorLog::general(CrawlErrorKind::Embedding, $message);
        Cache::put(self::AUTH_FAILURES_KEY, $failures, self::AUTH_CACHE_SECONDS);
        Cache::put(self::AUTH_RETRY_AT_KEY, $retryAt->getTimestamp(), self::AUTH_CACHE_SECONDS);
    }

    private function deferPage(SitemapPage $page, string $message): void
    {
        CrawlErrorLog::forPage($page, $message, null, CrawlErrorKind::Embedding);
        $page->refresh();
        $page->embedding_error = $message;
        $page->embedding_failure_count++;
        $page->embedding_retry_at = RetryBackoff::nextAt($page->embedding_failure_count);
        $page->save();
    }
}
