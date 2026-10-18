<?php

namespace App\Services;

/**
 * 空白を畳んだ本文の、文字3-gramのJaccard係数で一致を判定する。
 */
class ExtractionComparison
{
    public const float MINIMUM_SIMILARITY = 0.8;

    public function matches(string $left, string $right): bool
    {
        $left = $this->collapse($left);
        $right = $this->collapse($right);

        if ($left === '' || $right === '') {
            return $left === $right;
        }

        if (mb_strlen($left) < 3 || mb_strlen($right) < 3) {
            return $left === $right;
        }

        return $this->jaccard($left, $right) >= self::MINIMUM_SIMILARITY;
    }

    private function jaccard(string $left, string $right): float
    {
        $leftGrams = $this->trigrams($left);
        $rightGrams = $this->trigrams($right);
        $intersection = 0;

        foreach (array_keys($leftGrams) as $gram) {
            if (isset($rightGrams[$gram])) {
                $intersection++;
            }
        }

        $union = count($leftGrams) + count($rightGrams) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * @return array<string, true>
     */
    private function trigrams(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($characters === false) {
            return [];
        }

        $grams = [];
        $count = count($characters);

        for ($index = 0; $index <= $count - 3; $index++) {
            $grams[$characters[$index].$characters[$index + 1].$characters[$index + 2]] = true;
        }

        return $grams;
    }

    private function collapse(string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $text);

        return trim(is_string($collapsed) ? $collapsed : $text);
    }
}
