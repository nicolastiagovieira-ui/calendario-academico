<?php
declare(strict_types=1);

const CAL_ROOT = __DIR__ . '/..';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Calendario\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = CAL_ROOT . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

$example = require CAL_ROOT . '/config/config.example.php';
$localFile = CAL_ROOT . '/config/local.php';
$local = is_file($localFile) ? require $localFile : [];
$GLOBALS['cal_config'] = array_replace_recursive($example, is_array($local) ? $local : []);

date_default_timezone_set((string) $GLOBALS['cal_config']['app']['timezone']);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) $GLOBALS['cal_config']['app']['session_name']);
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

if ((bool) $GLOBALS['cal_config']['app']['require_host_session']) {
    $hostKey = (string) $GLOBALS['cal_config']['app']['host_session_key'];
    if (!isset($_SESSION[$hostKey])) {
        http_response_code(401);
        if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'codigo' => 'SESSAO_PRINCIPAL_NECESSARIA', 'erro' => 'Sessão principal expirada.']);
        } else {
            echo 'Sessão principal necessária.';
        }
        exit;
    }
}

function cal_config(?string $path = null, mixed $default = null): mixed {
    $value = $GLOBALS['cal_config'];
    if ($path === null) return $value;
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return $default;
        $value = $value[$key];
    }
    return $value;
}

function cal_csrf(): string {
    return $_SESSION['calendario_csrf'] ??= bin2hex(random_bytes(32));
}

function cal_user(): ?array {
    return isset($_SESSION['calendario_usuario_id']) ? [
        'id' => (int) $_SESSION['calendario_usuario_id'],
        'email' => (string) $_SESSION['calendario_email'],
        'perfil' => (string) $_SESSION['calendario_perfil'],
        'primeiroAcesso' => (bool) ($_SESSION['calendario_primeiro_acesso'] ?? false),
    ] : null;
}

function cal_require_user(bool $allowFirstAccess = false): array {
    $user = cal_user();
    if (!$user) throw new Calendario\HttpException(401, 'LOGIN_NECESSARIO', 'Faça o login adicional para continuar.');
    if (!$allowFirstAccess && $user['primeiroAcesso']) {
        throw new Calendario\HttpException(403, 'TROCA_SENHA_NECESSARIA', 'Altere sua senha de primeiro acesso.');
    }
    return $user;
}
