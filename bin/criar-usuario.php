<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Calendario\Database;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
[$script,$email,$profile] = array_pad($argv,3,null);
if (!$email || !filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($profile,['leitor','editor','administrador'],true)) {
    fwrite(STDERR,"Uso: php bin/criar-usuario.php usuario@example.com leitor|editor|administrador\n");exit(1);
}
fwrite(STDOUT,"Senha temporária: ");
$password=trim((string)fgets(STDIN));
if(strlen($password)<10){fwrite(STDERR,"A senha precisa ter ao menos 10 caracteres.\n");exit(1);}
$db=Database::connection();
$stmt=$db->prepare("INSERT INTO calendario_usuarios(email,senha_hash,perfil,primeiro_acesso,ativo) VALUES(?,?,?,1,1) ON DUPLICATE KEY UPDATE senha_hash=VALUES(senha_hash),perfil=VALUES(perfil),primeiro_acesso=1,ativo=1,tentativas_login=0,bloqueado_ate=NULL");
$stmt->execute([mb_strtolower($email),password_hash($password,PASSWORD_DEFAULT),$profile]);
fwrite(STDOUT,"Usuário criado/reativado. A troca de senha será exigida no primeiro acesso.\n");

