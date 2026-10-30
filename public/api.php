<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Calendario\AuthService;
use Calendario\CalendarService;
use Calendario\Database;
use Calendario\HttpException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function jsonBody(): array {
    $raw=file_get_contents('php://input');
    $data=json_decode($raw?:'{}',true);
    if(!is_array($data)) throw new HttpException(400,'JSON_INVALIDO','Conteúdo JSON inválido.');
    return $data;
}
function respond(array $data,int $status=200): never { http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit; }
function checkCsrf(array $body): void { if(!hash_equals(cal_csrf(),(string)($body['csrf']??''))) throw new HttpException(403,'CSRF_INVALIDO','A sessão expirou. Atualize a página.'); }

try {
    $action=(string)($_GET['action']??'session');
    $db=Database::connection();
    $auth=new AuthService($db);
    if($action==='login'){ $b=jsonBody();checkCsrf($b);respond(['ok'=>true,'usuario'=>$auth->login((string)($b['email']??''),(string)($b['senha']??''))]); }
    if($action==='logout'){ $b=jsonBody();checkCsrf($b);$auth->logout();respond(['ok'=>true]); }
    if($action==='session'){ respond(['ok'=>true,'usuario'=>cal_user(),'csrf'=>cal_csrf()]); }
    $user=cal_require_user($action==='change-password');
    if($action==='change-password'){ $b=jsonBody();checkCsrf($b);$auth->changePassword($user,(string)($b['senhaAtual']??''),(string)($b['novaSenha']??''));respond(['ok'=>true]); }
    $service=new CalendarService($db);
    if($action==='cities') respond(['ok'=>true,'dados'=>$service->cities()]);
    if($action==='month') respond(['ok'=>true,'dados'=>$service->month((int)($_GET['cidadeId']??0),(int)($_GET['ano']??0),(int)($_GET['mes']??0),$user)]);
    if($action==='toggle-pole'){ $b=jsonBody();checkCsrf($b);respond(['ok'=>true,'dados'=>$service->togglePole((int)($b['cidadeId']??0),(bool)($b['ativar']??false),(bool)($b['confirmar']??false),$user)]); }
    if($action==='save'){ $b=jsonBody();checkCsrf($b);respond(['ok'=>true,'dados'=>$service->save($b,$user)]); }
    throw new HttpException(404,'ROTA_NAO_ENCONTRADA','Operação não encontrada.');
} catch(HttpException $e){ respond(['ok'=>false,'codigo'=>$e->errorCode,'erro'=>$e->getMessage(),'detalhes'=>$e->details],$e->status); }
catch(Throwable $e){ $entry=sprintf("[%s] %s\n",date('c'),(string)$e);error_log('[calendario] '.$e);@file_put_contents(CAL_ROOT.'/var-app-error.log',$entry,FILE_APPEND|LOCK_EX);respond(['ok'=>false,'codigo'=>'ERRO_INTERNO','erro'=>'Não foi possível concluir a operação. Consulte o log do servidor.'],500); }
