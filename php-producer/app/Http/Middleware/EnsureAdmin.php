<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Пускает в API панели только вошедшего администратора.
 * Отвечает 401 в JSON: по этому коду Vue показывает форму входа.
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get(config('admin.session_key', 'admin.authenticated'))) {
            return response()->json(['message' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}