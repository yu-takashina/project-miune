<?php

namespace App\Models;

use Database\Factories\ApiKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * @property string $token
 * @property Carbon|null $last_used_at 最後に API で使った日時。未使用のときは null。
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['token'])]
class ApiKey extends Model
{
    /** @use HasFactory<ApiKeyFactory> */
    use HasFactory;

    public const LENGTH = 32;

    public const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+-_';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    public static function findByToken(string $token): ?self
    {
        if (preg_match('/\A[A-Za-z0-9+_-]{'.self::LENGTH.'}\z/', $token) !== 1) {
            return null;
        }

        return self::query()->where('token', $token)->first();
    }

    public function markUsed(): void
    {
        $this->last_used_at = now();
        $this->save();
    }

    public static function issue(): ApiKey
    {
        $attempts = 0;

        while ($attempts < 5) {
            $attempts++;

            try {
                return self::query()->create([
                    'token' => self::generateToken(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // 同じキーが既にあるときだけ引き直す。
            }
        }

        throw new RuntimeException('APIキーを発行できません。');
    }

    public static function generateToken(): string
    {
        $token = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($index = 0; $index < self::LENGTH; $index++) {
            $token .= self::ALPHABET[random_int(0, $last)];
        }

        return $token;
    }
}
