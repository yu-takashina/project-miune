<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Models\CrawlErrorLog;
use App\Models\SitemapPage;
use App\Services\ExtractSitemapPage;
use App\Services\HtmlDocumentDecoder;
use App\Support\RetryBackoff;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Throwable;

class CrawlSitemapPage implements ShouldQueue
{
    use Batchable, Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public const MAX_BODY_BYTES = 2 * 1024 * 1024;

    /** 応答を受け取れたら、取得失敗の回数と再試行の待ちを戻す。 */
    private const FETCHED = [
        'fetch_failure_count' => 0,
        'fetch_retry_at' => null,
    ];

    public function __construct(public int $sitemapPageId) {}

    public function handle(): void
    {
        $page = SitemapPage::query()->find($this->sitemapPageId);

        if ($page === null) {
            return;
        }

        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->withOptions(['stream' => true])
                ->withHeaders(['User-Agent' => 'miune'])
                ->get($page->url);
        } catch (Throwable $exception) {
            $this->markFetchFailed($page, $exception);

            return;
        }

        if ($response->status() === 404) {
            $response->toPsrResponse()->getBody()->close();
            $this->markFetchFailed($page, httpStatus: $response->status());

            return;
        }

        $this->storeResponse($page, $response);
    }

    private function storeResponse(SitemapPage $page, Response $response): void
    {
        $contentLength = $this->contentLength($response);
        $tooLarge = $contentLength !== null && $contentLength > self::MAX_BODY_BYTES;
        $body = '';
        $actualBytes = 0;

        if (! $tooLarge) {
            [$body, $actualBytes, $exceeded] = $this->readBody($response->toPsrResponse()->getBody());
            $tooLarge = $exceeded;
        }

        if ($tooLarge) {
            $message = 'レスポンスが大きすぎます。';
            CrawlErrorLog::forPage($page, $message, $response->status());
            $page->update([
                ...self::FETCHED,
                'http_status' => $response->status(),
                'response_bytes' => $contentLength ?? $actualBytes,
                'body' => null,
                'body_sha256' => null,
                'article_text' => null,
                'extraction_status' => null,
                'extracted_at' => null,
                'error_message' => $message,
                'crawled_at' => now(),
            ]);

            return;
        }

        $contentType = $response->header('Content-Type');
        $contentType = $contentType === '' ? null : $contentType;
        $body = (new HtmlDocumentDecoder)->toUtf8Body($body, $contentType);

        if ($body === null) {
            $message = '文字コードを UTF-8 に変換できません。';
            CrawlErrorLog::forPage($page, $message, $response->status());
            $page->update([
                ...self::FETCHED,
                'http_status' => $response->status(),
                'response_bytes' => $contentLength ?? $actualBytes,
                'body' => null,
                'body_sha256' => null,
                'article_text' => null,
                'extraction_status' => ExtractionStatus::Failed,
                'extracted_at' => now(),
                'error_message' => $message,
                'crawled_at' => now(),
            ]);

            return;
        }

        $hash = hash('sha256', $body);
        $bodyUnchanged = $page->body_sha256 !== null && hash_equals($page->body_sha256, $hash);
        $extractionFinished = $page->extracted_at !== null
            && $page->extraction_status !== ExtractionStatus::Failed;

        $values = [
            ...self::FETCHED,
            'http_status' => $response->status(),
            'response_bytes' => $contentLength ?? $actualBytes,
            'body' => $body,
            'body_sha256' => $hash,
            'error_message' => null,
            'crawled_at' => now(),
        ];

        if ($bodyUnchanged && $extractionFinished) {
            $page->update($values);

            return;
        }

        $page->retainEmbeddings = true;
        $values += [
            'article_text' => null,
            'extraction_status' => null,
            'extracted_at' => null,
        ];

        $page->update($values);
        $page->retainEmbeddings = false;

        try {
            app(ExtractSitemapPage::class)->apply($page, $this->utf8ContentType($contentType));
        } catch (Throwable $exception) {
            CrawlErrorLog::forPage($page, '本文を抽出できません。'.$exception->getMessage(), $response->status());
            $page->update([
                'article_text' => null,
                'extraction_status' => ExtractionStatus::Failed,
                'extracted_at' => now(),
            ]);
        }
    }

    private function utf8ContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        if (preg_match('/charset\s*=/i', $contentType) === 1) {
            return preg_replace('/charset\s*=\s*"?[A-Za-z0-9._:-]+"?/i', 'charset=UTF-8', $contentType) ?? $contentType;
        }

        return $contentType.'; charset=UTF-8';
    }

    /**
     * @return array{0: string, 1: int, 2: bool}
     */
    private function readBody(StreamInterface $stream): array
    {
        $body = '';
        $actualBytes = 0;
        $tooLarge = false;

        while (! $stream->eof()) {
            $chunk = $stream->read(8192);

            if ($chunk === '') {
                break;
            }

            $length = strlen($chunk);
            $actualBytes += $length;

            if ($tooLarge || $actualBytes > self::MAX_BODY_BYTES) {
                $tooLarge = true;
                $body = '';

                continue;
            }

            $body .= $chunk;
        }

        return [$body, $actualBytes, $tooLarge];
    }

    private function contentLength(Response $response): ?int
    {
        $header = $response->header('Content-Length');

        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    /**
     * 接続できないとき、または HTTP 404 のときは crawled_at を変えず、失敗が続くほど次の取得を遅らせる。
     */
    private function markFetchFailed(SitemapPage $page, ?Throwable $exception = null, ?int $httpStatus = null): void
    {
        $message = '取得に失敗しました。';
        $failures = $page->fetch_failure_count + 1;
        CrawlErrorLog::forPage($page, $message.($exception === null ? '' : $exception->getMessage()), $httpStatus);
        $page->update([
            'error_message' => $message,
            'fetch_failure_count' => $failures,
            'fetch_retry_at' => RetryBackoff::nextAt($failures),
        ]);
    }
}
