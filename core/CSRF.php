<?php

class CSRF
{
    public static function generate(): string
    {
        return Auth::csrfToken();
    }

    public static function validate(string $token): bool
    {
        return hash_equals(Auth::csrfToken(), $token);
    }

    public static function metaTag(): string
    {
        return '<meta name="csrf-token" content="' . htmlspecialchars(Auth::csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
