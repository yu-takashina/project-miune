<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public const ATTRIBUTE = 'api_key';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->headers->get('Authorization');
        $apiKey = null;

        if (is_string($header) && preg_match('/\ABearer\s+(\S+)\z/i', $header, $matches) === 1) {
            $apiKey = ApiKey::findByToken($matches[1]);
        }

        if (! $apiKey instanceof ApiKey) {
            return response()->json([
                'message' => 'APIキーが正しくありません。',
            ], 401);
        }

        $request->attributes->set(self::ATTRIBUTE, $apiKey);

        return $next($request);
    }
}
