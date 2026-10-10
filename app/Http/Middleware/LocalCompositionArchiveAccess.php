<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LocalCompositionArchiveAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('composition_archive_view.enabled') === true && app()->environment(['local', 'testing'])
            && in_array($request->server('REMOTE_ADDR'), ['127.0.0.1', '::1'], true);
        $response = $allowed ? $next($request) : new \Illuminate\Http\Response('見つかりません', 404);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");

        return $response;
    }
}
