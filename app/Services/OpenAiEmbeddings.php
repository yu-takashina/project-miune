<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class OpenAiEmbeddings
{
    public const DIMENSIONS = 1536;

    public const MAX_INPUT_CHARACTERS = 4000;

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $key = config('services.openai.key');

        if (! is_string($key) || $key === '') {
            throw new OpenAiEmbeddingException('OPENAI_API_KEY が未設定です。', false, true);
        }

        $model = config('services.openai.embedding_model');

        if (! is_string($model) || $model === '') {
            $model = 'text-embedding-3-small';
        }

        try {
            $response = Http::withToken($key)
                ->timeout(30)
                ->acceptJson()
                ->post('https://api.openai.com/v1/embeddings', [
                    'model' => $model,
                    'input' => mb_substr($text, 0, self::MAX_INPUT_CHARACTERS),
                    'dimensions' => self::DIMENSIONS,
                ]);
        } catch (ConnectionException $exception) {
            throw new OpenAiEmbeddingException('埋め込み API に接続できません。', false, false, $exception);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw new OpenAiEmbeddingException('OpenAI の認証に失敗しました。', false, true);
        }

        if ($response->failed()) {
            throw new OpenAiEmbeddingException(
                $this->failureMessage($response),
                $this->isPermanent($response),
                false,
            );
        }

        return $this->vector($response);
    }

    private function isPermanent(Response $response): bool
    {
        if ($response->status() !== 400) {
            return false;
        }

        $code = $response->json('error.code');

        if ($code === 'context_length_exceeded') {
            return true;
        }

        $message = $response->json('error.message');

        return is_string($message) && (
            str_contains($message, 'maximum context length')
            || str_contains($message, 'maximum input length')
        );
    }

    private function failureMessage(Response $response): string
    {
        if ($this->isPermanent($response)) {
            return '本文が長すぎてベクトル化できません。';
        }

        return '埋め込み API が失敗しました。';
    }

    /**
     * @return list<float>
     */
    private function vector(Response $response): array
    {
        $data = $response->json('data.0.embedding');

        if (! is_array($data) || count($data) !== self::DIMENSIONS) {
            throw new OpenAiEmbeddingException('埋め込みの次元が不正です。', false, false);
        }

        $vector = [];

        foreach ($data as $value) {
            if (is_int($value)) {
                $vector[] = (float) $value;

                continue;
            }

            if (! is_float($value)) {
                throw new OpenAiEmbeddingException('埋め込みの次元が不正です。', false, false);
            }

            $vector[] = $value;
        }

        return $vector;
    }
}
