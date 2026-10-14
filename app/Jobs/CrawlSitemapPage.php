<?php

namespace App\Jobs;

use App\Models\SitemapPage;
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

        $page->update([
            'http_status' => $response->status(),
            'response_bytes' => $contentLength ?? $actualBytes,
            'body' => $tooLarge ? null : $body,
            'body_sha256' => $tooLarge ? null : hash('sha256', $body),
            'error_message' => $tooLarge ? 'レスポンスが大きすぎます。' : null,
            'crawled_at' => now(),
        ]);
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
