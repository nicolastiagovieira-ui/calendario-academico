<?php
declare(strict_types=1);
namespace Calendario;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;
use Throwable;

final class CalendarService
{
    public function __construct(private readonly PDO $db) {}

    public function cities(): array
    {
        return $this->db->query("SELECT c.id,c.nome,c.sigla,c.tipo,c.ativo,
                                       MIN(cu.id_unidade) id_unidade,
                                       CASE WHEN c.tipo='polo' THEN MAX(ue.status='Ativo') ELSE c.ativo END ativo_unidade
                                FROM calendario_cidades c
                                JOIN calendario_cidade_unidades cu ON cu.cidade_id=c.id
                                JOIN UnidadesEscolares ue ON ue.idUnidade=cu.id_unidade
                                GROUP BY c.id,c.nome,c.sigla,c.tipo,c.ativo
                                ORDER BY CASE WHEN c.tipo='cidade' THEN 0 ELSE 1 END,c.nome COLLATE utf8mb4_unicode_ci")->fetchAll();
    }

    public function togglePole(int $cityId, bool $activate, bool $confirmed, array $user): array
    {
        if($user['perfil']!=='administrador') throw new HttpException(403,'PERFIL_SEM_PERMISSAO','Somente administradores podem ativar ou inativar polos.');
        if(!$confirmed) throw new HttpException(409,'CONFIRMACAO_OBRIGATORIA','Confirme explicitamente a alteração de status do polo.');
        $this->db->beginTransaction();
        try{
            $pole=$this->one("SELECT c.id,c.nome,c.ativo,cu.id_unidade,ue.status status_unidade FROM calendario_cidades c JOIN calendario_cidade_unidades cu ON cu.cidade_id=c.id JOIN UnidadesEscolares ue ON ue.idUnidade=cu.id_unidade WHERE c.id=? AND c.tipo='polo' FOR UPDATE",[$cityId]);
            if(!$pole) throw new HttpException(404,'POLO_NAO_ENCONTRADO','Polo não encontrado.');
            $newStatus=$activate?'Ativo':'Inativo';
            $this->db->prepare('UPDATE UnidadesEscolares SET status=? WHERE idUnidade=?')->execute([$newStatus,$pole['id_unidade']]);
            $this->db->prepare('UPDATE calendario_cidades SET ativo=? WHERE id=?')->execute([$activate?1:0,$cityId]);
            $this->db->prepare("INSERT INTO calendario_alteracoes(usuario_id,cidade_id,status,total_inclusoes,total_remocoes,ip,user_agent,concluido_em) VALUES(?,?,'aplicada',0,0,INET6_ATON(?),?,NOW())")->execute([$user['id'],$cityId,$_SERVER['REMOTE_ADDR']??null,substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
            $changeId=(int)$this->db->lastInsertId();
            $this->logItem($changeId,'unidade_status_alterado','UnidadesEscolares',(string)$pole['id_unidade'],['status'=>$pole['status_unidade']],['status'=>$newStatus]);
            $this->db->commit();
            return ['cidadeId'=>$cityId,'idUnidade'=>(int)$pole['id_unidade'],'nome'=>$pole['nome'],'ativo'=>$activate,'status'=>$newStatus,'alteracaoId'=>$changeId];
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }

    public function month(int $cityId, int $year, int $month, array $user): array
    {
        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2100) throw new HttpException(422, 'PERIODO_INVALIDO', 'Mês ou ano inválido.');
        $city = $this->one('SELECT id,nome,sigla,tipo,ativo FROM calendario_cidades WHERE id=?', [$cityId]);
        if (!$city) throw new HttpException(404, 'CIDADE_NAO_ENCONTRADA', 'Cidade não encontrada.');
        $unit = $this->one("SELECT ue.idUnidade,ue.nome,ue.max_atendimentos FROM calendario_cidade_unidades cu JOIN UnidadesEscolares ue ON ue.idUnidade=cu.id_unidade WHERE cu.cidade_id=? ORDER BY (cu.finalidade='geral') DESC,cu.id_unidade LIMIT 1",[$cityId]);
        $capacity=(int)($unit['max_atendimentos']??0);
        $city['unidade']=['id'=>(int)($unit['idUnidade']??0),'nome'=>$unit['nome']??$city['nome'],'vagasDia'=>$capacity,'salas'=>$capacity>0?(int)ceil($capacity/25):0,'vagasPorSala'=>25];
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        $stages = $this->all(
            "SELECT e.id,e.nome,e.nome_curto,e.cor,e.destino_sp,e.categoria,e.ordem,
                    CASE e.cor WHEN '#D9D9D9' THEN 10 WHEN '#19E62B' THEN 20
                         WHEN '#6D9EEB' THEN 30 WHEN '#FFC000' THEN 40
                         WHEN '#FF00FF' THEN 50 ELSE 90 END ordem_cor,
                    COALESCE(legado.vagas,v.vagas,1) vagas
             FROM calendario_etapas e
             LEFT JOIN calendario_etapa_vagas v ON v.etapa_id=e.id AND v.cidade_id=?
             LEFT JOIN (
                 SELECT ed.etapa_id,MAX(du.max_atendimentos_disciplina) vagas
                 FROM calendario_cidade_unidades cu
                 JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade
                 JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina
                 JOIN calendario_etapas destino ON destino.id=ed.etapa_id
                 WHERE cu.cidade_id=? AND (
                     cu.finalidade=destino.destino_sp OR
                     (cu.finalidade='geral' AND NOT EXISTS (
                         SELECT 1 FROM calendario_cidade_unidades preferida
                         WHERE preferida.cidade_id=cu.cidade_id
                           AND preferida.finalidade=destino.destino_sp
                     ))
                 )
                 GROUP BY ed.etapa_id
             ) legado ON legado.etapa_id=e.id
             WHERE e.ativo=1 ORDER BY FIELD(e.categoria,'estetica','ozonio','hof'),ordem_cor,e.ordem,e.nome", [$cityId,$cityId]
        );
        $events = $this->legacyVisibleEvents($cityId, $start, $end);
        $dailyExtras = $this->all(
            "SELECT de.data,ed.etapa_id,MAX(de.max_atendimentos) quantidade
             FROM calendario_cidade_unidades cu
             JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade
             JOIN DiaExtra de ON de.idRelacionamento=du.idRelacionamento
             JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina
             JOIN calendario_etapas e ON e.id=ed.etapa_id AND (
                 e.destino_sp=cu.finalidade OR
                 (cu.finalidade='geral' AND NOT EXISTS (
                     SELECT 1 FROM calendario_cidade_unidades preferida
                     WHERE preferida.cidade_id=cu.cidade_id
                       AND preferida.finalidade=e.destino_sp
                 ))
             )
             WHERE cu.cidade_id=? AND de.data BETWEEN ? AND ?
             GROUP BY de.data,ed.etapa_id", [$cityId,$start,$end]
        );
        $blocks = $this->all(
            'SELECT id,data_bloqueio,tipo,observacao FROM calendario_bloqueios WHERE cidade_id=? AND ativo=1 AND data_bloqueio BETWEEN ? AND ? ORDER BY data_bloqueio',
            [$cityId, $start, $end]
        );
        $unitLimits = $this->all('SELECT veu.data,MIN(veu.max_atendimentos) limite FROM vagasExtrasUnidades veu JOIN calendario_cidade_unidades cu ON cu.id_unidade=veu.idUnidade WHERE cu.cidade_id=? AND veu.data BETWEEN ? AND ? GROUP BY veu.data',[$cityId,$start,$end]);
        $roomRows = $this->all(
            "SELECT t.idTurma,t.idUnidade,t.nome,t.max_vagas,ed.etapa_id
             FROM calendario_cidade_unidades cu
             JOIN Turmas t ON t.idUnidade=cu.id_unidade AND t.status='Ativo'
             JOIN TurmasDisciplinas td ON td.idTurma=t.idTurma
             JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=td.idDisciplina
             JOIN calendario_etapas e ON e.id=ed.etapa_id AND e.ativo=1
             WHERE cu.cidade_id=?
             GROUP BY t.idTurma,t.idUnidade,t.nome,t.max_vagas,ed.etapa_id
             ORDER BY t.idUnidade,t.nome,ed.etapa_id", [$cityId]
        );
        $rooms = [];
        foreach ($roomRows as $row) {
            $roomId=(int)$row['idTurma'];
            $rooms[$roomId]??=['id'=>$roomId,'idUnidade'=>(int)$row['idUnidade'],'nome'=>$row['nome'],'maxVagas'=>(int)$row['max_vagas'],'etapaIds'=>[]];
            $rooms[$roomId]['etapaIds'][]=(int)$row['etapa_id'];
        }
        $days = [];
        foreach ($events as $event) $days[$event['data_evento']]['etapas'][] = (int) $event['etapa_id'];
        foreach ($dailyExtras as $extra) $days[$extra['data']]['vagasExtras'][(int)$extra['etapa_id']] = (int)$extra['quantidade'];
        foreach ($blocks as $block) $days[$block['data_bloqueio']]['bloqueio'] = ['tipo' => $block['tipo'], 'observacao' => $block['observacao']];
        foreach ($unitLimits as $limit) $days[$limit['data']]['limiteUnidade']=(int)$limit['limite'];
        $version = $this->version($cityId);
        return [
            'cidade' => $city, 'periodo' => ['ano' => $year, 'mes' => $month],
            'etapas' => array_map(fn($s) => [...$s, 'id'=>(int)$s['id'], 'vagas'=>(int)$s['vagas']], $stages),
            'dias' => $days, 'salas' => array_values($rooms), 'versao' => $version, 'fonte' => 'DisciplinasUnidades',
            'permissoes' => ['editar' => in_array($user['perfil'], ['editor','administrador'], true), 'administrador' => $user['perfil']==='administrador'],
        ];
    }

    public function save(array $payload, array $user): array
    {
        set_time_limit(120);
        if (!in_array($user['perfil'], ['editor','administrador'], true)) throw new HttpException(403, 'PERFIL_SEM_PERMISSAO', 'Seu perfil permite apenas leitura.');
        $cityId = filter_var($payload['cidadeId'] ?? null, FILTER_VALIDATE_INT);
        if (!$cityId) throw new HttpException(422, 'CIDADE_INVALIDA', 'Cidade inválida.');
        $adds = $this->normalizeEvents($payload['eventosAdicionar'] ?? []);
        $removes = $this->normalizeEvents($payload['eventosRemover'] ?? []);
        $blockAdds = $this->normalizeBlocks($payload['bloqueiosDefinir'] ?? []);
        $blockRemoves = array_values(array_unique(array_map(fn($v) => $this->date((string)$v), $payload['bloqueiosRemover'] ?? [])));
        $vacancies = $this->normalizeVacancies($payload['vagas'] ?? []);
        $dailyExtras = $this->normalizeDailyExtras($payload['vagasExtras'] ?? []);
        $dailyExtraRemoves = $this->normalizeEvents($payload['vagasExtrasRemover'] ?? []);
        $requiresLegacyProjection = (bool)($adds || $removes || $vacancies || array_filter($blockAdds,fn($item)=>!in_array($item['tipo'],['bloqueado','limitado'],true)));
        $dates = array_merge(array_column($adds,'data'), array_column($removes,'data'), array_column($blockAdds,'data'), $blockRemoves, array_column($dailyExtras,'data'), array_column($dailyExtraRemoves,'data'));
        $past = array_values(array_filter(array_unique($dates), fn($d) => $d < date('Y-m-d')));
        if ($past && $user['perfil'] !== 'administrador') throw new HttpException(403, 'DATA_PASSADA_SEM_PERMISSAO', 'Você não tem permissão para alterar essa data, pois ela já passou. Contate o administrador.');
        if ($past && (empty($payload['confirmarDataPassada']) || trim((string)($payload['motivoDataPassada'] ?? '')) === '')) {
            throw new HttpException(409, 'CONFIRMACAO_DATA_PASSADA', 'Confirme a alteração de data passada e informe o motivo.', ['datas'=>$past]);
        }

        $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $this->db->beginTransaction();
        $changeId = null;
        try {
            $this->one('SELECT id FROM calendario_cidades WHERE id=? FOR UPDATE', [$cityId]);
            $currentVersion = $this->version($cityId);
            if (($payload['versaoBase'] ?? '') !== $currentVersion) throw new HttpException(409, 'CALENDARIO_ALTERADO', 'O calendário foi alterado por outra pessoa. Recarregue antes de salvar.');
            $stmt = $this->db->prepare('INSERT INTO calendario_alteracoes(usuario_id,cidade_id,status,contem_data_passada,confirmacao_data_passada,motivo_data_passada,ip,user_agent) VALUES(?, ?, \'processando\', ?, ?, ?, INET6_ATON(?), ?)');
            $stmt->execute([$user['id'],$cityId,$past?1:0,!empty($payload['confirmarDataPassada'])?1:0,$payload['motivoDataPassada']??null,$_SERVER['REMOTE_ADDR']??null,substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
            $changeId = (int)$this->db->lastInsertId();
            $counts = ['inclusoes'=>0,'remocoes'=>0,'cancelamentos'=>0];
            $synced=$this->syncLegacyEvents($cityId,(int)$user['id'],$changeId);
            $counts['inclusoes']+=$synced['inclusoes'];
            $counts['remocoes']+=$synced['remocoes'];
            foreach ($adds as $item) {
                $before = $this->eventState($cityId,$item['etapaId'],$item['data']);
                $this->db->prepare('INSERT INTO calendario_eventos(cidade_id,etapa_id,data_evento,ativo,criado_por,atualizado_por) VALUES(?,?,?,1,?,?) ON DUPLICATE KEY UPDATE ativo=1,atualizado_por=VALUES(atualizado_por)')->execute([$cityId,$item['etapaId'],$item['data'],$user['id'],$user['id']]);
                if (!$before) { $counts['inclusoes']++; $this->logItem($changeId,'evento_adicionado','calendario_eventos',null,null,$item); }
            }
            foreach ($removes as $item) {
                $stmt=$this->db->prepare('UPDATE calendario_eventos SET ativo=0,atualizado_por=? WHERE cidade_id=? AND etapa_id=? AND data_evento=? AND ativo=1');
                $stmt->execute([$user['id'],$cityId,$item['etapaId'],$item['data']]);
                if ($stmt->rowCount()) { $counts['remocoes']++; $this->logItem($changeId,'evento_removido','calendario_eventos',null,$item,null); }
                $this->deleteDailyExtras($cityId,$item['etapaId'],$item['data']);
            }
            foreach ($blockAdds as $item) {
                $this->db->prepare('INSERT INTO calendario_bloqueios(cidade_id,data_bloqueio,tipo,observacao,ativo,criado_por,atualizado_por) VALUES(?,?,?,?,1,?,?) ON DUPLICATE KEY UPDATE tipo=VALUES(tipo),observacao=VALUES(observacao),ativo=1,atualizado_por=VALUES(atualizado_por)')->execute([$cityId,$item['data'],$item['tipo'],$item['observacao'],$user['id'],$user['id']]);
                $counts['inclusoes']++; $this->logItem($changeId,'bloqueio_adicionado','calendario_bloqueios',null,null,$item);
            }
            foreach ($blockRemoves as $date) {
                $existingBlock=$this->one('SELECT tipo FROM calendario_bloqueios WHERE cidade_id=? AND data_bloqueio=? AND ativo=1',[$cityId,$date]);
                $stmt=$this->db->prepare('UPDATE calendario_bloqueios SET ativo=0,atualizado_por=? WHERE cidade_id=? AND data_bloqueio=? AND ativo=1');
                $stmt->execute([$user['id'],$cityId,$date]);
                if ($stmt->rowCount()) { if(in_array($existingBlock['tipo']??null,['bloqueado','limitado'],true))$restored=$this->clearUnitDayCapacity($cityId,$date);else{$restored=0;$requiresLegacyProjection=true;}$counts['remocoes']++; $this->logItem($changeId,'bloqueio_removido','calendario_bloqueios',null,['data'=>$date,'unidades_restauradas'=>$restored],null); }
            }
            foreach ($vacancies as $item) {
                $old=$this->one('SELECT vagas FROM calendario_etapa_vagas WHERE cidade_id=? AND etapa_id=?',[$cityId,$item['etapaId']]);
                $this->db->prepare('INSERT INTO calendario_etapa_vagas(cidade_id,etapa_id,vagas,atualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE vagas=VALUES(vagas),atualizado_por=VALUES(atualizado_por)')->execute([$cityId,$item['etapaId'],$item['quantidade'],$user['id']]);
                if (!$old || (int)$old['vagas']!==$item['quantidade']) $this->logItem($changeId,'vagas_alteradas','calendario_etapa_vagas',null,$old,$item);
            }
            foreach ($dailyExtraRemoves as $item) {
                $removed=$this->deleteDailyExtras($cityId,$item['etapaId'],$item['data']);
                if($removed)$this->logItem($changeId,'vagas_extras_alteradas','DiaExtra',$item['data'],['etapaId'=>$item['etapaId'],'registros'=>$removed,'max_atendimentos'=>0],null);
            }
            foreach ($dailyExtras as $item) {
                $relations=$this->stageRelations($cityId,$item['etapaId']);
                if(!$relations) throw new HttpException(422,'ETAPA_SEM_RELACIONAMENTO','A etapa não possui relacionamento com esta unidade.');
                foreach($relations as $relation){
                    $old=$this->one('SELECT max_atendimentos,descricao FROM DiaExtra WHERE idRelacionamento=? AND data=?',[$relation['idRelacionamento'],$item['data']]);
                    $this->db->prepare("INSERT INTO DiaExtra(idRelacionamento,data,max_atendimentos,descricao) VALUES(?,?,?,'Calendário administrativo') ON DUPLICATE KEY UPDATE max_atendimentos=VALUES(max_atendimentos),descricao=VALUES(descricao)")->execute([$relation['idRelacionamento'],$item['data'],$item['quantidade']]);
                    if(!$old||(int)$old['max_atendimentos']!==$item['quantidade']) $this->logItem($changeId,'vagas_extras_alteradas','DiaExtra',(string)$relation['idRelacionamento'],$old,$item);
                }
            }
            foreach ($blockAdds as $item) {
                if(in_array($item['tipo'],['bloqueado','limitado'],true)){
                    $amount=$item['tipo']==='bloqueado'?0:(int)$item['quantidade'];
                    $units=$this->setUnitDayCapacity($cityId,$item['data'],$amount,$item['observacao']);
                    $this->logItem($changeId,'vagas_extras_alteradas','vagasExtrasUnidades',$item['data'],null,['max_atendimentos'=>$amount,'unidades'=>$units,'tipo'=>$item['tipo']]);
                }
            }
            if ($requiresLegacyProjection && \cal_config('app.legacy_sync', true)) (new LegacyProjectionService($this->db))->project($cityId,$changeId);
            $this->db->prepare("UPDATE calendario_alteracoes SET status='aplicada',total_inclusoes=?,total_remocoes=?,total_cancelamentos=?,concluido_em=NOW() WHERE id=?")->execute([$counts['inclusoes'],$counts['remocoes'],$counts['cancelamentos'],$changeId]);
            $this->db->commit();
            return ['alteracaoId'=>$changeId,'resumo'=>$counts,'novaVersao'=>$this->version($cityId)];
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }

    private function normalizeEvents(array $items): array { $out=[]; foreach($items as $i){$id=filter_var($i['etapaId']??null,FILTER_VALIDATE_INT);if(!$id)throw new HttpException(422,'ETAPA_INVALIDA','Etapa inválida.');$out[]=['etapaId'=>(int)$id,'data'=>$this->date((string)($i['data']??''))];} return $out; }
    private function legacyMonthEvents(int $cityId,string $start,string $end): array
    {
        $rows=$this->all(
            "SELECT DISTINCT e.id etapa_id,du.data_inicio,du.data_fim,du.feriados
             FROM calendario_cidade_unidades cu
             JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade
             JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina
             JOIN calendario_etapas e ON e.id=ed.etapa_id AND e.ativo=1
             WHERE cu.cidade_id=? AND (
                 cu.finalidade=e.destino_sp OR
                 (cu.finalidade='geral' AND NOT EXISTS (
                     SELECT 1 FROM calendario_cidade_unidades preferida
                     WHERE preferida.cidade_id=cu.cidade_id
                       AND preferida.finalidade=e.destino_sp
                 ))
             )
               AND du.data_inicio<=? AND du.data_fim>=?", [$cityId,$end,$start]
        );
        $events=[];
        foreach($rows as $row){
            $from=max($start,(string)$row['data_inicio']);$to=min($end,(string)$row['data_fim']);
            $closed=array_flip(array_filter(explode(',',str_replace(' ','',(string)$row['feriados']))));
            $period=new DatePeriod(new DateTimeImmutable($from),new DateInterval('P1D'),(new DateTimeImmutable($to))->modify('+1 day'));
            foreach($period as $day){$date=$day->format('Y-m-d');if(!isset($closed[$date]))$events[$row['etapa_id'].':'.$date]=['id'=>null,'etapa_id'=>(int)$row['etapa_id'],'data_evento'=>$date];}
        }
        return array_values($events);
    }
    private function legacyVisibleEvents(int $cityId,string $start,string $end): array
    {
        $events=[];
        foreach($this->legacyMonthEvents($cityId,$start,$end) as $event)$events[$event['etapa_id'].':'.$event['data_evento']]=$event;
        $protected=$this->all("SELECT ev.id,ev.etapa_id,ev.data_evento FROM calendario_eventos ev JOIN calendario_bloqueios b ON b.cidade_id=ev.cidade_id AND b.data_bloqueio=ev.data_evento AND b.ativo=1 AND b.tipo IN ('feriado','folga','ferias','recesso') WHERE ev.cidade_id=? AND ev.ativo=1 AND ev.data_evento BETWEEN ? AND ?",[$cityId,$start,$end]);
        foreach($protected as $event)$events[$event['etapa_id'].':'.$event['data_evento']]=['id'=>$event['id'],'etapa_id'=>(int)$event['etapa_id'],'data_evento'=>$event['data_evento']];
        return array_values($events);
    }
    private function syncLegacyEvents(int $cityId,int $userId,int $changeId): array
    {
        $bounds=$this->one("SELECT DATE_FORMAT(MIN(du.data_inicio),'%Y-%m-%d') inicio,DATE_FORMAT(MAX(du.data_fim),'%Y-%m-%d') fim FROM calendario_cidade_unidades cu JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade WHERE cu.cidade_id=? AND du.data_inicio IS NOT NULL AND du.data_fim IS NOT NULL AND du.data_inicio<=du.data_fim",[$cityId]);
        $start=$bounds['inicio']??date('Y-01-01');$end=$bounds['fim']??date('Y-12-31');
        if($end<$start)return ['inclusoes'=>0,'remocoes'=>0];
        $target=[];
        foreach($this->legacyMonthEvents($cityId,$start,$end) as $event)$target[$event['etapa_id'].':'.$event['data_evento']]=$event;
        $protected=$this->all("SELECT ev.id,ev.etapa_id,ev.data_evento FROM calendario_eventos ev JOIN calendario_bloqueios b ON b.cidade_id=ev.cidade_id AND b.data_bloqueio=ev.data_evento AND b.ativo=1 AND b.tipo IN ('feriado','folga','ferias','recesso') WHERE ev.cidade_id=? AND ev.ativo=1 AND ev.data_evento BETWEEN ? AND ?",[$cityId,$start,$end]);
        foreach($protected as $event)$target[$event['etapa_id'].':'.$event['data_evento']]=$event;
        $current=[];
        foreach($this->all('SELECT id,etapa_id,data_evento,ativo FROM calendario_eventos WHERE cidade_id=? AND data_evento BETWEEN ? AND ?',[$cityId,$start,$end]) as $event)$current[$event['etapa_id'].':'.$event['data_evento']]=$event;
        $upsert=$this->db->prepare('INSERT INTO calendario_eventos(cidade_id,etapa_id,data_evento,ativo,criado_por,atualizado_por) VALUES(?,?,?,1,?,?) ON DUPLICATE KEY UPDATE ativo=1,atualizado_por=VALUES(atualizado_por)');
        $disable=$this->db->prepare('UPDATE calendario_eventos SET ativo=0,atualizado_por=? WHERE id=? AND ativo=1');
        $counts=['inclusoes'=>0,'remocoes'=>0];
        foreach($target as $key=>$event){if(isset($current[$key])&&(int)$current[$key]['ativo']===1)continue;$upsert->execute([$cityId,$event['etapa_id'],$event['data_evento'],$userId,$userId]);$counts['inclusoes']++;$this->logItem($changeId,'evento_adicionado','calendario_eventos',null,null,['origem'=>'sincronizacao_legado','etapaId'=>$event['etapa_id'],'data'=>$event['data_evento']]);}
        foreach($current as $key=>$event){if(!(int)$event['ativo']||isset($target[$key]))continue;$disable->execute([$userId,$event['id']]);if($disable->rowCount()){$counts['remocoes']++;$this->logItem($changeId,'evento_removido','calendario_eventos',(string)$event['id'],['origem'=>'sincronizacao_legado','etapaId'=>(int)$event['etapa_id'],'data'=>$event['data_evento']],null);}}
        $legacyVacancies=$this->all("SELECT ed.etapa_id,MAX(du.max_atendimentos_disciplina) vagas FROM calendario_cidade_unidades cu JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina JOIN calendario_etapas e ON e.id=ed.etapa_id WHERE cu.cidade_id=? AND (cu.finalidade=e.destino_sp OR (cu.finalidade='geral' AND NOT EXISTS (SELECT 1 FROM calendario_cidade_unidades preferida WHERE preferida.cidade_id=cu.cidade_id AND preferida.finalidade=e.destino_sp))) GROUP BY ed.etapa_id",[$cityId]);
        $vacancy=$this->db->prepare('INSERT INTO calendario_etapa_vagas(cidade_id,etapa_id,vagas,atualizado_por) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE vagas=VALUES(vagas),atualizado_por=VALUES(atualizado_por)');
        foreach($legacyVacancies as $item){$old=$this->one('SELECT vagas FROM calendario_etapa_vagas WHERE cidade_id=? AND etapa_id=?',[$cityId,$item['etapa_id']]);$amount=max(1,(int)$item['vagas']);if($old&&(int)$old['vagas']===$amount)continue;$vacancy->execute([$cityId,$item['etapa_id'],$amount,$userId]);$this->logItem($changeId,'vagas_alteradas','calendario_etapa_vagas',null,$old,['origem'=>'sincronizacao_legado','etapaId'=>(int)$item['etapa_id'],'quantidade'=>$amount]);}
        return $counts;
    }
    private function normalizeBlocks(array $items): array { $types=['feriado','folga','ferias','recesso','bloqueado','limitado'];$out=[];foreach($items as $i){$type=$i['tipo']??'';if(!in_array($type,$types,true))throw new HttpException(422,'BLOQUEIO_INVALIDO','Tipo de bloqueio inválido.');$quantity=null;if($type==='limitado'){$quantity=filter_var($i['quantidade']??null,FILTER_VALIDATE_INT);if($quantity===false||$quantity<1||$quantity>500)throw new HttpException(422,'LIMITE_UNIDADE_INVALIDO','O limite da unidade deve estar entre 1 e 500.');}$out[]=['data'=>$this->date((string)($i['data']??'')),'tipo'=>$type,'quantidade'=>$quantity,'observacao'=>substr(trim((string)($i['observacao']??'')),0,255)?:null];}return $out; }
    private function normalizeVacancies(array $items): array { $max=(int)($this->one("SELECT valor FROM calendario_configuracoes WHERE chave='limite_maximo_vagas'")['valor']??500);$out=[];foreach($items as $i){$id=filter_var($i['etapaId']??null,FILTER_VALIDATE_INT);$q=filter_var($i['quantidade']??null,FILTER_VALIDATE_INT);if(!$id||!$q||$q<1||$q>$max)throw new HttpException(422,'VAGAS_INVALIDAS',"Vagas devem estar entre 1 e $max.");$out[]=['etapaId'=>(int)$id,'quantidade'=>(int)$q];}return $out; }
    private function normalizeDailyExtras(array $items): array { $max=(int)($this->one("SELECT valor FROM calendario_configuracoes WHERE chave='limite_maximo_vagas'")['valor']??500);$out=[];foreach($items as $i){$id=filter_var($i['etapaId']??null,FILTER_VALIDATE_INT);$q=filter_var($i['quantidade']??null,FILTER_VALIDATE_INT);if(!$id||$q===false||$q<0||$q>$max)throw new HttpException(422,'VAGAS_EXTRAS_INVALIDAS',"Vagas do dia devem estar entre 0 e $max.");$out[]=['etapaId'=>(int)$id,'data'=>$this->date((string)($i['data']??'')),'quantidade'=>(int)$q];}return $out; }
    private function stageRelations(int $cityId,int $stageId): array { return $this->all("SELECT du.idRelacionamento FROM calendario_cidade_unidades cu JOIN calendario_etapas e ON e.id=? AND (e.destino_sp=cu.finalidade OR (cu.finalidade='geral' AND NOT EXISTS (SELECT 1 FROM calendario_cidade_unidades preferida WHERE preferida.cidade_id=cu.cidade_id AND preferida.finalidade=e.destino_sp))) JOIN calendario_etapa_disciplinas ed ON ed.etapa_id=e.id JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade AND du.idDisciplina=ed.id_disciplina WHERE cu.cidade_id=?",[$stageId,$cityId]); }
    private function deleteDailyExtras(int $cityId,int $stageId,string $date): int { $relations=$this->stageRelations($cityId,$stageId);if(!$relations)return 0;$stmt=$this->db->prepare('DELETE FROM DiaExtra WHERE idRelacionamento=? AND data=?');$removed=0;foreach($relations as $relation){$stmt->execute([$relation['idRelacionamento'],$date]);$removed+=$stmt->rowCount();}return $removed; }
    private function setUnitDayCapacity(int $cityId,string $date,int $amount,?string $description): int { $units=$this->all('SELECT id_unidade FROM calendario_cidade_unidades WHERE cidade_id=?',[$cityId]);if(!$units)return 0;$values=[];$params=[];foreach($units as $unit){$values[]='(?,?,?,?)';array_push($params,$unit['id_unidade'],$date,$amount,$description?:($amount===0?'Bloqueio integral do calendário':'Limite diário do calendário'));}$this->db->prepare('INSERT INTO vagasExtrasUnidades(idUnidade,data,max_atendimentos,descricao) VALUES '.implode(',',$values).' ON DUPLICATE KEY UPDATE max_atendimentos=VALUES(max_atendimentos),descricao=VALUES(descricao)')->execute($params);return count($units); }
    private function clearUnitDayCapacity(int $cityId,string $date): int { $stmt=$this->db->prepare('DELETE veu FROM vagasExtrasUnidades veu JOIN calendario_cidade_unidades cu ON cu.id_unidade=veu.idUnidade WHERE cu.cidade_id=? AND veu.data=?');$stmt->execute([$cityId,$date]);return $stmt->rowCount(); }
    private function date(string $date): string { $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date)throw new HttpException(422,'DATA_INVALIDA','Data inválida.');return $date; }
    private function version(int $cityId): string { $log=(string)($this->one("SELECT COALESCE(DATE_FORMAT(MAX(concluido_em),'%Y-%m-%dT%H:%i:%s.%f'),'0') versao FROM calendario_alteracoes WHERE cidade_id=? AND status='aplicada'",[$cityId])['versao']??'0');$legacy=$this->one("SELECT COUNT(*) total,COALESCE(BIT_XOR(CRC32(CONCAT_WS('|',du.idRelacionamento,COALESCE(du.data_inicio,''),COALESCE(du.data_fim,''),COALESCE(du.feriados,''),COALESCE(du.max_atendimentos_disciplina,'')))),0) assinatura FROM calendario_cidade_unidades cu JOIN DisciplinasUnidades du ON du.idUnidade=cu.id_unidade WHERE cu.cidade_id=?",[$cityId]);return $log.':'.($legacy['total']??0).':'.($legacy['assinatura']??0); }
    private function eventState(int $c,int $e,string $d): bool { return (bool)$this->one('SELECT id FROM calendario_eventos WHERE cidade_id=? AND etapa_id=? AND data_evento=? AND ativo=1',[$c,$e,$d]); }
    private function logItem(int $id,string $type,string $table,?string $record,mixed $old,mixed $new): void { $this->db->prepare('INSERT INTO calendario_alteracao_itens(alteracao_id,tipo,tabela_afetada,registro_id,valor_anterior,valor_novo) VALUES(?,?,?,?,?,?)')->execute([$id,$type,$table,$record,$old===null?null:json_encode($old,JSON_UNESCAPED_UNICODE),$new===null?null:json_encode($new,JSON_UNESCAPED_UNICODE)]); }
    private function one(string $sql,array $p=[]): array|false { $s=$this->db->prepare($sql);$s->execute($p);return $s->fetch(); }
    private function all(string $sql,array $p=[]): array { $s=$this->db->prepare($sql);$s->execute($p);return $s->fetchAll(); }
}
