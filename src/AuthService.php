<?php
declare(strict_types=1);
namespace Calendario;

use PDO;

final class AuthService
{
    public function __construct(private readonly PDO $db) {}

    public function login(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') throw new HttpException(401, 'CREDENCIAIS_INVALIDAS', 'Usuário ou senha inválidos.');
        $stmt = $this->db->prepare('SELECT * FROM calendario_usuarios WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        $blocked = $row && $row['bloqueado_ate'] && strtotime($row['bloqueado_ate']) > time();
        if (!$row || !$row['ativo'] || $blocked || !password_verify($password, $row['senha_hash'])) {
            if ($row && !$blocked) {
                $attempts = ((int) $row['tentativas_login']) + 1;
                $until = $attempts >= 5 ? date('Y-m-d H:i:s', time() + 900) : null;
                $this->db->prepare('UPDATE calendario_usuarios SET tentativas_login=?, bloqueado_ate=? WHERE id=?')->execute([$attempts, $until, $row['id']]);
            }
            throw new HttpException(401, 'CREDENCIAIS_INVALIDAS', 'Usuário ou senha inválidos.');
        }
        $this->db->prepare('UPDATE calendario_usuarios SET tentativas_login=0, bloqueado_ate=NULL, ultimo_login_em=NOW() WHERE id=?')->execute([$row['id']]);
        session_regenerate_id(true);
        $_SESSION['calendario_usuario_id'] = (int) $row['id'];
        $_SESSION['calendario_email'] = $row['email'];
        $_SESSION['calendario_perfil'] = $row['perfil'];
        $_SESSION['calendario_primeiro_acesso'] = (bool) $row['primeiro_acesso'];
        $_SESSION['calendario_autenticado_em'] = time();
        return \cal_user();
    }

    public function changePassword(array $user, string $current, string $new): void
    {
        if (strlen($new) < 10 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/\d/', $new)) {
            throw new HttpException(422, 'SENHA_FRACA', 'Use ao menos 10 caracteres, com maiúscula, minúscula e número.');
        }
        $stmt = $this->db->prepare('SELECT senha_hash FROM calendario_usuarios WHERE id=? AND ativo=1');
        $stmt->execute([$user['id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) throw new HttpException(422, 'SENHA_ATUAL_INVALIDA', 'A senha atual não confere.');
        $this->db->prepare('UPDATE calendario_usuarios SET senha_hash=?, primeiro_acesso=0 WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $_SESSION['calendario_primeiro_acesso'] = false;
    }

    public function logout(): void
    {
        foreach (array_keys($_SESSION) as $key) if (str_starts_with($key, 'calendario_')) unset($_SESSION[$key]);
        session_regenerate_id(true);
    }
}
