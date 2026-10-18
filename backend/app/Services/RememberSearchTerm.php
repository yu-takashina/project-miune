<?php

namespace App\Services;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use stdClass;

class RememberSearchTerm
{
    private const LOCK_SECONDS = 45;

    public function __construct(private OpenAiEmbeddings $embeddings = new OpenAiEmbeddings) {}

    /**
     * @return list<float>
     */
    public function vector(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            throw new InvalidArgumentException('検索語が空です。');
        }

        $model = $this->embeddings->model();
        $hash = hash('sha256', $term);
        $stored = $this->find($hash, $model);

        if ($stored !== null) {
            return $stored;
        }

        $lock = Cache::lock($this->lockKey($model, $hash), self::LOCK_SECONDS);

        try {
            $lock->block(self::LOCK_SECONDS);
        } catch (LockTimeoutException $exception) {
            throw new OpenAiEmbeddingException('埋め込み API が失敗しました。', false, false, $exception);
        }

        try {
            return $this->resolve($term, $hash, $model);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<float>
     */
    private function resolve(string $term, string $hash, string $model): array
    {
        $stored = $this->find($hash, $model);

        if ($stored !== null) {
            return $stored;
        }

        $vector = $this->embeddings->embed($term);

        try {
            $this->store($term, $hash, $model, $vector);
        } catch (UniqueConstraintViolationException) {
            $stored = $this->find($hash, $model);

            if ($stored === null) {
                throw new OpenAiEmbeddingException('埋め込みを保存できません。', false, false);
            }

            return $stored;
        } catch (QueryException $exception) {
            throw new OpenAiEmbeddingException('埋め込みを保存できません。', false, false, $exception);
        }

        return $vector;
    }

    /**
     * @return list<float>|null
     */
    private function find(string $hash, string $model): ?array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $exists = DB::table('search_terms')
                ->where('term_sha256', $hash)
                ->where('model', $model)
                ->exists();

            if (! $exists) {
                return null;
            }

            return $this->vectorFromStored(DB::table('search_terms')
                ->where('term_sha256', $hash)
                ->where('model', $model)
                ->value('embedding'));
        }

        $row = DB::selectOne(
            'select VEC_ToText(embedding) as embedding from search_terms where term_sha256 = ? and model = ?',
            [$hash, $model],
        );

        if (! $row instanceof stdClass) {
            return null;
        }

        return $this->vectorFromStored($row->embedding ?? null);
    }

    /**
     * @param  list<float>  $vector
     */
    private function store(string $term, string $hash, string $model, array $vector): void
    {
        try {
            $encoded = json_encode($vector, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenAiEmbeddingException('埋め込みを保存できません。', false, false, $exception);
        }

        $embeddedAt = now()->format('Y-m-d H:i:s');

        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::table('search_terms')->insert([
                'term' => $term,
                'term_sha256' => $hash,
                'model' => $model,
                'embedding' => $encoded,
                'embedded_at' => $embeddedAt,
            ]);

            return;
        }

        DB::insert(
            'insert into search_terms (term, term_sha256, model, embedding, embedded_at) values (?, ?, ?, VEC_FromText(?), ?)',
            [$term, $hash, $model, $encoded, $embeddedAt],
        );
    }

    /**
     * @return list<float>
     */
    private function vectorFromStored(mixed $stored): array
    {
        if (! is_string($stored) || $stored === '') {
            throw new OpenAiEmbeddingException('埋め込みの次元が不正です。', false, false);
        }

        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new OpenAiEmbeddingException('埋め込みの次元が不正です。', false, false, $exception);
        }

        if (! is_array($decoded) || count($decoded) !== OpenAiEmbeddings::DIMENSIONS) {
            throw new OpenAiEmbeddingException('埋め込みの次元が不正です。', false, false);
        }

        $vector = [];

        foreach ($decoded as $value) {
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

    private function lockKey(string $model, string $hash): string
    {
        return 'search-term:'.$model.':'.$hash;
    }
}
