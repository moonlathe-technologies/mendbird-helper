<?php

namespace Mendbird\Helper;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Answers Mendbird's signed request, and nobody else's. Without the right signature, or with a time more than five
 * minutes out, the answer is "Not found", so nobody else can even tell the helper is there.
 */
class AnswerController
{
    public function __invoke(Request $request): Response
    {
        $key = (string) config('mendbird.key');
        $host = (string) config('mendbird.host');
        $time = (string) $request->header('Mendbird-Time', '');
        $sign = strtolower((string) $request->header('Mendbird-Sign', ''));
        if ($key === '' || $host === '' || !ctype_digit($time) || abs(time() - (int) $time) > 300 || strlen($sign) !== 64
            || !hash_equals(hash_hmac('sha256', 'pull:' . $host . ':' . $time, $key), $sign)) {
            return new Response("Not found\n", 404, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $body = json_encode(Lister::list(base_path()), JSON_UNESCAPED_SLASHES);
        return new Response($body, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
            'Mendbird-Sign' => hash_hmac('sha256', 'reply:' . $time . ':' . $body, $key),
        ]);
    }
}
