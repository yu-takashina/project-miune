<?php

namespace App\Jobs;

use App\Enums\ExtractionStatus;
use App\Models\SitemapPage;
use App\Services\ExtractSitemapPage;
use App\Services\HtmlDocumentDecoder;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
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
        } catch (ConnectionException) {
            $this->markFetchFailed($page);

            return;
        } catch (Throwable) {
            $this->markFetchFailed($page);

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
            $page->update([
                'http_status' => $response->status(),
                'response_bytes' => $contentLength ?? $actualBytes,
                'body' => null,
                'body_sha256' => null,
                'article_text' => null,
                'extraction_status' => null,
                'extracted_at' => null,
                'error_message' => 'レスポンスが大きすぎます。',
                'crawled_at' => now(),
            ]);

            return;
        }

        $contentType = $response->header('Content-Type');
        $contentType = $contentType === '' ? null : $contentType;
        $body = (new HtmlDocumentDecoder)->toUtf8Body($body, $contentType);

        if ($body === null) {
            $page->update([
                'http_status' => $response->status(),
                'response_bytes' => $contentLength ?? $actualBytes,
                'body' => null,
                'body_sha256' => null,
                'article_text' => null,
                'extraction_status' => ExtractionStatus::Failed,
                'extracted_at' => now(),
                'error_message' => '文字コードを UTF-8 に変換できません。',
                'crawled_at' => now(),
            ]);

            return;
        }

        $hash = hash('sha256', $body);
        $alreadyExtracted = $page->body_sha256 !== null
            && hash_equals($page->body_sha256, $hash)
            && $page->extracted_at !== null
            && $page->extraction_status !== ExtractionStatus::Failed;

        $values = [
            'http_status' => $response->status(),
            'response_bytes' => $contentLength ?? $actualBytes,
            'body' => $body,
            'body_sha256' => $hash,
            'error_message' => null,
            'crawled_at' => now(),
        ];

        if (! $alreadyExtracted) {
            $values += [
                'article_text' => null,
                'extraction_status' => null,
                'extracted_at' => null,
            ];
        }

        $page->update($values);

        if ($alreadyExtracted) {
            return;
        }

        try {
            app(ExtractSitemapPage::class)->apply($page, $this->utf8ContentType($contentType));
        } catch (Throwable) {
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

    private function markFetchFailed(SitemapPage $page): void
    {
        $page->update([
            'error_message' => '取得に失敗しました。',
            'crawled_at' => $page->crawled_at ?? now(),
        ]);
    }
}
