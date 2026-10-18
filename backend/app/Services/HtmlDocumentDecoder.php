<?php

namespace App\Services;

/**
 * レスポンスを UTF-8 に変換し、HTML のときはタイトルも取り出す。
 */
class HtmlDocumentDecoder
{
    private const int SAMPLE_BYTES = 8192;

    /**
     * @return array{html: string, title: string}|null
     */
    public function decode(string $body, ?string $contentType): ?array
    {
        $mediaType = $this->mediaType($contentType);
        $isHtml = $mediaType === null
            ? $this->looksLikeHtml($body)
            : $this->isHtmlMediaType($mediaType);

        if (! $isHtml) {
            return null;
        }

        $html = $this->toUtf8Body($body, $contentType);

        if ($html === null) {
            return null;
        }

        return [
            'html' => $html,
            'title' => $this->title($html),
        ];
    }

    /**
     * MariaDB の utf8mb4 列に保存できる文字列へ変換する。
     */
    public function toUtf8Body(string $body, ?string $contentType): ?string
    {
        $charset = $this->charsetFromHeader($contentType) ?? $this->charsetFromHtml($body);

        if ($charset === null) {
            if (mb_check_encoding($body, 'UTF-8')) {
                return $this->removeBom($body);
            }

            $charset = mb_detect_encoding($body, ['SJIS-win', 'EUC-JP', 'ISO-2022-JP'], true);

            if (! is_string($charset)) {
                return null;
            }
        }

        $converted = $this->convertToUtf8($body, $charset);

        if ($converted === null) {
            return null;
        }

        return $this->markHtmlAsUtf8($this->removeBom($converted));
    }

    private function mediaType(?string $contentType): ?string
    {
        if ($contentType === null || trim($contentType) === '') {
            return null;
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    private function isHtmlMediaType(string $mediaType): bool
    {
        return $mediaType === 'text/html' || $mediaType === 'application/xhtml+xml';
    }

    private function looksLikeHtml(string $body): bool
    {
        return preg_match('/<(?:!doctype\s+html|html|head|body)\b/i', substr($body, 0, self::SAMPLE_BYTES)) === 1;
    }

    private function charsetFromHeader(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        if (preg_match('/charset\s*=\s*"?([A-Za-z0-9._:-]+)/i', $contentType, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function charsetFromHtml(string $body): ?string
    {
        $sample = substr($body, 0, self::SAMPLE_BYTES);

        if (preg_match('/<meta\b[^>]*charset\s*=\s*["\']?\s*([A-Za-z0-9._:-]+)/i', $sample, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function convertToUtf8(string $body, string $charset): ?string
    {
        $canonical = $this->canonicalEncoding($charset);

        if ($canonical === null) {
            return null;
        }

        if ($canonical === 'UTF-8') {
            return mb_check_encoding($body, 'UTF-8') ? $body : null;
        }

        if (! mb_check_encoding($body, $canonical)) {
            return null;
        }

        $converted = mb_convert_encoding($body, 'UTF-8', $canonical);

        if (! is_string($converted) || ! mb_check_encoding($converted, 'UTF-8')) {
            return null;
        }

        return $converted;
    }

    private function removeBom(string $body): string
    {
        return preg_replace('/^\x{FEFF}/u', '', $body) ?? $body;
    }

    /**
     * 保存した本文を後から読むときは HTML 中の指定しか手がかりがないため、ヘッダと食い違っても UTF-8 にそろえる。
     */
    private function markHtmlAsUtf8(string $body): string
    {
        return preg_replace_callback(
            '/(<meta\b[^>]*charset\s*=\s*["\']?\s*)([A-Za-z0-9._:-]+)/i',
            fn (array $matches): string => $this->canonicalEncoding($matches[2]) === 'UTF-8'
                ? $matches[0]
                : $matches[1].'UTF-8',
            $body,
        ) ?? $body;
    }

    private function canonicalEncoding(string $charset): ?string
    {
        $name = strtolower(trim($charset));
        $aliases = [
            'utf8' => 'UTF-8',
            'utf-8' => 'UTF-8',
            'shift_jis' => 'SJIS-win',
            'shift-jis' => 'SJIS-win',
            'sjis' => 'SJIS-win',
            'x-sjis' => 'SJIS-win',
            'windows-31j' => 'SJIS-win',
            'cp932' => 'SJIS-win',
            'euc-jp' => 'EUC-JP',
            'eucjp' => 'EUC-JP',
            'iso-2022-jp' => 'ISO-2022-JP',
        ];

        $canonical = $aliases[$name] ?? $charset;

        foreach (mb_list_encodings() as $encoding) {
            if (strtolower($encoding) === strtolower($canonical)) {
                return $encoding === 'UTF-8' || strtolower($encoding) === 'utf-8' ? 'UTF-8' : $encoding;
            }
        }

        if (strtoupper($canonical) === 'UTF-8') {
            return 'UTF-8';
        }

        return null;
    }

    private function title(string $html): string
    {
        if (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $matches) !== 1) {
            return '';
        }

        $title = html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $title);

        return trim(is_string($collapsed) ? $collapsed : $title);
    }
}
