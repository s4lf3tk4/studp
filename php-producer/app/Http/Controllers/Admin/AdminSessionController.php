<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** Вход и выход в панели типографии: обычная сессия Laravel (SESSION_DRIVER=redis) */
final class AdminSessionController
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        if (! $this->credentialsMatch($data['login'], $data['password'])) {
            return response()->json(['message' => 'Неверный логин или пароль'], 401);
        }

        // Новая сессия после входа — защита от фиксации сессии
        $request->session()->regenerate();
        $request->session()->put(config('admin.session_key', 'admin.authenticated'), true);
        $request->session()->put(config('admin.session_login_key', 'admin.login'), $data['login']);

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /**
     * Логин из .env, сравнение без утечки времени.
     * Пустые значения = вход запрещён (лучше закрыто, чем открыто).
     */
    private function credentialsMatch(string $login, string $password): bool
    {
        $expectedLogin = (string) config('admin.login', '');
        $hash = (string) config('admin.password_hash', '');
        $plain = (string) config('admin.password', '');

        if ($expectedLogin === '' || ($hash === '' && $plain === '')) {
            return false;
        }

        if (! hash_equals($expectedLogin, $login)) {
            return false;
        }

        return $hash !== '' ? Hash::check($password, $hash) : hash_equals($plain, $password);
    }
}