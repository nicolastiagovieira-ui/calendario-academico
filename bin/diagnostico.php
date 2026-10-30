<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Calendario\Database;
use Calendario\CalendarService;
use Calendario\LegacyProjectionService;
use Calendario\AuthService;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$db=Database::connection();
$checks=[
 'tabelas_calendario'=>"SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'calendario\\_%'",
 'usuarios_ativos'=>"SELECT COUNT(*) FROM calendario_usuarios WHERE ativo=1",
 'cidades_ativas'=>"SELECT COUNT(*) FROM calendario_cidades WHERE ativo=1",
 'etapas_ativas'=>"SELECT COUNT(*) FROM calendario_etapas WHERE ativo=1",
 'eventos_ativos'=>"SELECT COUNT(*) FROM calendario_eventos WHERE ativo=1",
 'mapeamentos'=>"SELECT COUNT(*) FROM calendario_etapa_disciplinas",
];
foreach($checks as $label=>$sql) printf("%-24s %d\n",$label,(int)$db->query($sql)->fetchColumn());

if (in_array('--dia-extra', $argv, true)) {
    echo "\nEstrutura DiaExtra\n";
    $create = $db->query('SHOW CREATE TABLE DiaExtra')->fetch(PDO::FETCH_ASSOC);
    echo ($create['Create Table'] ?? 'Tabela não encontrada') . "\n";

    echo "\nAmostra DiaExtra\n";
    foreach ($db->query('SELECT * FROM DiaExtra LIMIT 10')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }
}

if (in_array('--catalogo', $argv, true)) {
    echo "\nCatálogo por categoria\n";
    foreach ($db->query("SELECT categoria,COUNT(*) etapas FROM calendario_etapas WHERE ativo=1 GROUP BY categoria ORDER BY FIELD(categoria,'estetica','ozonio','hof')")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        printf("%-12s %d\n", $row['categoria'], $row['etapas']);
    }
    echo "eventos_hof              " . (int)$db->query("SELECT COUNT(*) FROM calendario_eventos ev JOIN calendario_etapas e ON e.id=ev.etapa_id WHERE ev.ativo=1 AND e.categoria='hof'")->fetchColumn() . "\n";
    $cityId=(int)$db->query("SELECT id FROM calendario_cidades WHERE sigla='SPO' LIMIT 1")->fetchColumn();
    $month=(new CalendarService($db))->month($cityId,(int)date('Y'),(int)date('m'),['perfil'=>'administrador']);
    echo "retorno_mes_etapas       " . count($month['etapas']) . "\n";
    echo "retorno_mes_salas        " . count($month['salas'] ?? []) . "\n";
    echo "capacidade_unidade       " . (int)($month['cidade']['unidade']['vagasDia'] ?? 0) . "\n";
    echo "salas_unidade            " . (int)($month['cidade']['unidade']['salas'] ?? 0) . "\n";
}

if (in_array('--testar-projecao', $argv, true)) {
    $cityId=(int)$db->query("SELECT id FROM calendario_cidades WHERE sigla='SPO' LIMIT 1")->fetchColumn();
    $started=microtime(true);
    $db->beginTransaction();
    try {
        (new LegacyProjectionService($db))->project($cityId,0);
    } finally {
        $db->rollBack();
    }
    printf("projecao_sp_segundos      %.3f (rollback)\n",microtime(true)-$started);
}

if (in_array('--testar-varredura', $argv, true)) {
    $sample=$db->query("SELECT c.id cidade_id,cu.id_unidade,du.idRelacionamento,du.feriados,e.id etapa_id
                        FROM calendario_cidades c
                        JOIN calendario_cidade_unidades cu ON cu.cidade_id=c.id
                        JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade
                        JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina
                        JOIN calendario_etapas e ON e.id=ed.etapa_id AND e.ativo=1
                        WHERE du.data_inicio<=CURRENT_DATE AND du.data_fim>=CURRENT_DATE
                          AND FIND_IN_SET(DATE_FORMAT(CURRENT_DATE,'%Y-%m-%d'),REPLACE(COALESCE(du.feriados,''),' ',''))=0
                          AND (SELECT COUNT(*)
                               FROM calendario_etapa_disciplinas ed2
                               JOIN DisciplinasUnidades du2
                                 ON du2.idDisciplina=ed2.id_disciplina
                                AND du2.idUnidade=du.idUnidade
                               WHERE ed2.etapa_id=e.id)=1
                          AND (cu.finalidade=e.destino_sp OR
                               (cu.finalidade='geral' AND NOT EXISTS (
                                   SELECT 1 FROM calendario_cidade_unidades preferida
                                   WHERE preferida.cidade_id=cu.cidade_id
                                     AND preferida.finalidade=e.destino_sp
                               )))
                        ORDER BY c.id,du.idRelacionamento LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if(!$sample){echo "varredura_legado          sem amostra elegivel\n";}
    else{
        $service=new CalendarService($db);$today=date('Y-m-d');
        $before=$service->month((int)$sample['cidade_id'],(int)date('Y'),(int)date('m'),['perfil'=>'administrador']);
        $keyPresent=static fn(array $month):bool=>in_array((int)$sample['etapa_id'],$month['dias'][$today]['etapas']??[],true);
        $original=(string)($sample['feriados']??'');
        $closed=implode(',',array_values(array_unique(array_filter([...explode(',',str_replace(' ','',$original)),$today]))));
        $db->beginTransaction();
        try{
            $db->prepare('UPDATE DisciplinasUnidades SET feriados=? WHERE idRelacionamento=?')->execute([$closed,$sample['idRelacionamento']]);
            $during=$service->month((int)$sample['cidade_id'],(int)date('Y'),(int)date('m'),['perfil'=>'administrador']);
            printf("varredura_legado          fonte=%s versao_mudou=%s evento_antes=%s evento_durante=%s (rollback)\n",
                $during['fonte'],$before['versao']!==$during['versao']?'sim':'nao',$keyPresent($before)?'sim':'nao',$keyPresent($during)?'sim':'nao');
        }finally{$db->rollBack();}
    }
}

if (in_array('--testar-bloqueio', $argv, true)) {
    $sample=$db->query("SELECT ev.cidade_id,ev.data_evento FROM calendario_eventos ev WHERE ev.ativo=1 GROUP BY ev.cidade_id,ev.data_evento ORDER BY COUNT(*) DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $service=new CalendarService($db);
    $method=new ReflectionMethod($service,'setUnitDayCapacity');
    $started=microtime(true);
    $db->beginTransaction();
    try {
        $relations=$method->invoke($service,(int)$sample['cidade_id'],$sample['data_evento'],0,'Teste revertido');
    } finally {
        $db->rollBack();
    }
    printf("bloqueio_lote_segundos    %.3f (%d relacionamentos, rollback)\n",microtime(true)-$started,$relations);
}

if (in_array('--roteamento', $argv, true)) {
    echo "\nDermatofuncional por cidade\n";
    $sql="SELECT c.sigla,COUNT(DISTINCT ed.etapa_id) etapas,COUNT(DISTINCT du.idRelacionamento) relacionamentos
          FROM calendario_cidades c
          JOIN calendario_cidade_unidades cu ON cu.cidade_id=c.id
          JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade
          JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina
          JOIN calendario_etapas e ON e.id=ed.etapa_id AND e.nome LIKE 'Dermatofuncional%'
          WHERE cu.finalidade=e.destino_sp OR
                (cu.finalidade='geral' AND NOT EXISTS (
                    SELECT 1 FROM calendario_cidade_unidades preferida
                    WHERE preferida.cidade_id=cu.cidade_id AND preferida.finalidade=e.destino_sp
                ))
          GROUP BY c.id,c.sigla ORDER BY c.sigla";
    foreach($db->query($sql)->fetchAll(PDO::FETCH_ASSOC) as $row) printf("%-6s etapas=%d relacionamentos=%d\n",$row['sigla'],$row['etapas'],$row['relacionamentos']);
    $rio=$db->query("SELECT COUNT(*) eventos,COUNT(DISTINCT ev.etapa_id) etapas,MIN(ev.data_evento) primeira_data,MAX(ev.data_evento) ultima_data FROM calendario_eventos ev JOIN calendario_etapas e ON e.id=ev.etapa_id JOIN calendario_cidades c ON c.id=ev.cidade_id WHERE c.sigla='RIO' AND e.nome LIKE 'Dermatofuncional%' AND ev.ativo=1")->fetch(PDO::FETCH_ASSOC);
    printf("RIO eventos=%d etapas=%d periodo=%s..%s\n",$rio['eventos'],$rio['etapas'],$rio['primeira_data']??'-',$rio['ultima_data']??'-');
}

if (in_array('--unidades-schema', $argv, true)) {
    echo "\nTabelas contendo Unidade\n";
    foreach($db->query("SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND LOWER(table_name) LIKE '%unidade%' ORDER BY table_name")->fetchAll(PDO::FETCH_COLUMN) as $table) echo $table."\n";
    foreach(['Unidades','UnidadesEscolares'] as $table){
        $exists=$db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$exists->execute([$table]);
        if(!(int)$exists->fetchColumn())continue;
        echo "\nEstrutura {$table}\n";
        $row=$db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
        echo ($row['Create Table']??'')."\n";
    }
}

if (in_array('--auth', $argv, true)) {
    echo "\nEstado dos usuários do calendário\n";
    foreach($db->query('SELECT id,email,perfil,ativo,primeiro_acesso,tentativas_login,bloqueado_ate FROM calendario_usuarios ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    }
    echo "\nPermissões do usuário técnico\n";
    foreach($db->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN) as $grant) echo $grant."\n";
}

if (in_array('--testar-login', $argv, true)) {
    try {
        (new AuthService($db))->login('__diagnostico_inexistente__','nao-utilizada');
        echo "Falha: o login inexistente foi aceito.\n";
    } catch (Throwable $e) {
        printf("login_excecao=%s\nmensagem=%s\narquivo=%s:%d\n",$e::class,$e->getMessage(),$e->getFile(),$e->getLine());
    }
}

