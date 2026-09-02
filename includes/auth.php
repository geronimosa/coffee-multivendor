<?php
declare(strict_types=1);

function login_user(PDO $pdo, string $email, string $password): bool
{
    $stmt = $pdo->prepare('SELECT id, email, name, role, password_hash FROM users WHERE email = ? AND active = 1 LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
        password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'] ?: $user['email'];
    $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$user['id']]);
    audit_log($pdo, 'auth.login', 'user', (string) $user['id']);
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function require_super_admin(): void
{
    if (empty($_SESSION['user_id'])) {
        redirect('/super/login.php');
    }

    global $pdo;
    $stmt = $pdo->prepare('SELECT role, active FROM users WHERE id=? LIMIT 1');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $account = $stmt->fetch();
    if (!$account || !(int) $account['active'] || $account['role'] !== 'super_admin') {
        logout_user();
        http_response_code(403);
        exit('Access denied.');
    }

    $_SESSION['user_role'] = $account['role'];
}

function audit_log(PDO $pdo, string $action, ?string $entityType = null, ?string $entityId = null, ?int $vendorId = null, array $metadata = []): void
{
    $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, vendor_id, action, entity_type, entity_id, metadata, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['user_id'] ?? null,
        $vendorId,
        $action,
        $entityType,
        $entityId,
        $metadata ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
