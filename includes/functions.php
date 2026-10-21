<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function e(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!current_user()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function require_role(array $roles): void {
    require_login();
    if (!in_array(current_user()['role'], $roles, true)) {
        http_response_code(403);
        exit('Access denied: your account does not have permission to view this page.');
    }
}

function flash(string $message, string $type = 'success'): void {
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('Your session token expired. Please go back, refresh the page, and try again.');
    }
}

function redirect(string $page): never {
    header('Location: index.php?page=' . urlencode($page));
    exit;
}

function money(float|string $amount): string {
    return '₹' . number_format((float)$amount, 2);
}
