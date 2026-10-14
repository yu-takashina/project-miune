<?php

namespace App\Http\Requests\Manager;

use App\Models\Sitemap;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreSitemapRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|Closure>>
     */
    public function rules(): array
    {
        return [
            'url' => [
                'required',
                'string',
                'url:http,https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $registered = Sitemap::query()
                        ->where('url_sha256', hash('sha256', $value))
                        ->exists();

                    if ($registered) {
                        $fail('この URL は登録済みです。');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'url.required' => 'URL を入力してください。',
            'url.url' => 'URL は http または https で指定してください。',
        ];
    }
}
