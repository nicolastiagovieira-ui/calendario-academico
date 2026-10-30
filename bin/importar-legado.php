<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Calendario\Database;
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$db=Database::connection();
$userId=(int)$db->query("SELECT id FROM calendario_usuarios WHERE ativo=1 AND perfil='administrador' ORDER BY id LIMIT 1")->fetchColumn();
if(!$userId) throw new RuntimeException('Crie um administrador antes da importação.');
$existing=(int)$db->query('SELECT COUNT(*) FROM calendario_eventos')->fetchColumn();
if($existing>0 && !in_array('--complementar',$argv,true)) throw new RuntimeException("Já existem $existing eventos. Use --complementar para inserir somente os ausentes.");
$category=null;
foreach($argv as $arg) if(str_starts_with($arg,'--categoria=')) $category=substr($arg,12);
if($category!==null&&!in_array($category,['estetica','ozonio','hof'],true)) throw new RuntimeException('Categoria inválida.');
$sql="SELECT c.id cidade_id,e.id etapa_id,e.categoria,du.data_inicio,du.data_fim,du.feriados,du.max_atendimentos_disciplina
      FROM calendario_cidades c
      JOIN calendario_cidade_unidades cu ON cu.cidade_id=c.id
      JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade
      JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina
      JOIN calendario_etapas e ON e.id=ed.etapa_id AND e.ativo=1
      WHERE c.ativo=1 AND du.data_inicio IS NOT NULL AND du.data_fim IS NOT NULL
        AND du.data_inicio<=du.data_fim
        AND (cu.finalidade=e.destino_sp OR
             (cu.finalidade='geral' AND NOT EXISTS (
                 SELECT 1 FROM calendario_cidade_unidades preferida
                 WHERE preferida.cidade_id=cu.cidade_id
                   AND preferida.finalidade=e.destino_sp
             )))";
$rows=$db->query($sql)->fetchAll();$events=[];$vacancies=[];
foreach($rows as $row){
    if($category!==null&&$row['categoria']!==$category) continue;
    $key=$row['cidade_id'].':'.$row['etapa_id'];
    $vacancies[$key]=max($vacancies[$key]??0,(int)$row['max_atendimentos_disciplina']);
    $closed=[];
    foreach(explode(',',str_replace(' ','',(string)$row['feriados'])) as $date)if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))$closed[$date]=true;
    $period=new DatePeriod(new DateTimeImmutable($row['data_inicio']),new DateInterval('P1D'),(new DateTimeImmutable($row['data_fim']))->modify('+1 day'));
    foreach($period as $day){$date=$day->format('Y-m-d');if(!isset($closed[$date]))$events[$key.':'.$date]=[(int)$row['cidade_id'],(int)$row['etapa_id'],$date];}
}
$db->beginTransaction();
try{
    foreach(array_chunk(array_values($events),200) as $batch){
        $values=[];$params=[];
        foreach($batch as [$city,$stage,$date]){$values[]='(?,?,?,1,?,?)';array_push($params,$city,$stage,$date,$userId,$userId);}
        $duplicate=in_array('--complementar',$argv,true)?'id=id':'ativo=VALUES(ativo),atualizado_por=VALUES(atualizado_por)';
        $db->prepare('INSERT INTO calendario_eventos(cidade_id,etapa_id,data_evento,ativo,criado_por,atualizado_por) VALUES '.implode(',',$values).' ON DUPLICATE KEY UPDATE '.$duplicate)->execute($params);
    }
    $vacancyRows=[];
    foreach($vacancies as $key=>$amount){if($amount<1)continue;[$city,$stage]=array_map('intval',explode(':',$key));$vacancyRows[]=[$city,$stage,$amount,$userId];}
    foreach(array_chunk($vacancyRows,200) as $batch){
        $values=[];$params=[];
        foreach($batch as $row){$values[]='(?,?,?,?)';array_push($params,...$row);}
        $db->prepare('INSERT INTO calendario_etapa_vagas(cidade_id,etapa_id,vagas,atualizado_por) VALUES '.implode(',',$values).' ON DUPLICATE KEY UPDATE vagas=GREATEST(vagas,VALUES(vagas)),atualizado_por=VALUES(atualizado_por)')->execute($params);
    }
    $db->commit();printf("Importação concluída: %d eventos e %d configurações de vagas.\n",count($events),count($vacancies));
}catch(Throwable $e){$db->rollBack();throw $e;}
