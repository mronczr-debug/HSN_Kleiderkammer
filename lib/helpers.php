<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
        'use_strict_mode' => true,
        'use_only_cookies' => true,
    ]);
}

if (!function_exists('e')) {
    function e(?string $s): string {
        return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }
        return $_SESSION['csrf'];
    }
}

if (!function_exists('csrf_check')) {
    function csrf_check(string $t): bool {
        return hash_equals($_SESSION['csrf'] ?? '', $t);
    }
}

if (!function_exists('flash')) {
    function flash(string $key, ?string $msg = null): ?string {
        if ($msg !== null) {
            $_SESSION['flash_'.$key] = $msg;
            return null;
        }
        $val = $_SESSION['flash_'.$key] ?? null;
        unset($_SESSION['flash_'.$key]);
        return $val;
    }
}

/* ------- Pfade & URLs ------- */
if (!function_exists('base_url')) {
    function base_url(): string {
        $dir = rtrim(str_replace('\\','/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return ($dir === '/' || $dir === '.' || $dir === '') ? '' : $dir;
    }
}

if (!function_exists('project_root_dir')) {
    function project_root_dir(): string {
        // /lib -> Projektwurzel
        return str_replace('\\','/', dirname(__DIR__));
    }
}
if (!function_exists('public_dir')) {
    function public_dir(): string {
        return project_root_dir().'/public';
    }
}
if (!function_exists('uploads_dir')) {
    function uploads_dir(): string {
        $dir = public_dir().'/uploads';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        return $dir;
    }
}
if (!function_exists('uploads_url')) {
    function uploads_url(): string {
        return base_url().'/uploads';
    }
}
if (!function_exists('safe_join')) {
    function safe_join(string $base, string $rel): string {
        $base = rtrim($base, '/');
        $rel  = ltrim($rel, '/');
        return $base.'/'.$rel;
    }
}
if (!function_exists('random_name')) {
    function random_name(string $ext=''): string {
        $n = bin2hex(random_bytes(8));
        $ext = $ext ? ('.'.ltrim($ext,'.')) : '';
        return $n.$ext;
    }
}
