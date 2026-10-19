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
        // Приводим к строке: null из конфига увёл бы проверку в get(null),
        // а get(null) возвращает всю сессию — она всегда непустая, там _token
        $key = (string) config('admin.session_key', 'admin.authenticated');

        if ($key === '' || ! $request->session()->has($key)) {
            return response()->json(['message' => 'unauthenticated'], 401);
        }

        return $next($request);
    }
}