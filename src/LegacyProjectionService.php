<?php
declare(strict_types=1);
namespace Calendario;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use PDO;

final class LegacyProjectionService
{
    public function __construct(private readonly PDO $db) {}

    public function project(int $cityId, int $changeId): void
    {
        $units=$this->query('SELECT id_unidade,finalidade FROM calendario_cidade_unidades WHERE cidade_id=?',[$cityId]);
        $start=(new DateTimeImmutable('first day of January this year'))->format('Y-m-d');
        foreach($units as $unit){
            $endStmt=$this->db->prepare("SELECT MAX(ev.data_evento) FROM calendario_eventos ev JOIN calendario_etapas e ON e.id=ev.etapa_id WHERE ev.cidade_id=? AND ev.ativo=1 AND (e.destino_sp=? OR (?='geral' AND NOT EXISTS (SELECT 1 FROM calendario_cidade_unidades preferida WHERE preferida.cidade_id=? AND preferida.finalidade=e.destino_sp)))");
            $endStmt->execute([$cityId,$unit['finalidade'],$unit['finalidade'],$cityId]);$end=$endStmt->fetchColumn();
            if(!$end || $end<$start) continue;
            $stages=$this->query("SELECT e.id etapa_id,COALESCE(v.vagas,MAX(du.max_atendimentos_disciplina),1) vagas FROM DisciplinasUnidades du JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina JOIN calendario_etapas e ON e.id=ed.etapa_id LEFT JOIN calendario_etapa_vagas v ON v.cidade_id=? AND v.etapa_id=e.id WHERE du.idUnidade=? AND (e.destino_sp=? OR (?='geral' AND NOT EXISTS (SELECT 1 FROM calendario_cidade_unidades preferida WHERE preferida.cidade_id=? AND preferida.finalidade=e.destino_sp))) GROUP BY e.id,v.vagas",[$cityId,$unit['id_unidade'],$unit['finalidade'],$unit['finalidade'],$cityId]);
            $eventStmt=$this->db->prepare('SELECT data_evento FROM calendario_eventos WHERE cidade_id=? AND etapa_id=? AND ativo=1 AND data_evento BETWEEN ? AND ?');
            $blockStmt=$this->db->prepare("SELECT data_bloqueio FROM calendario_bloqueios WHERE cidade_id=? AND ativo=1 AND tipo IN ('feriado','folga','ferias','recesso') AND data_bloqueio BETWEEN ? AND ?");
            $blockStmt->execute([$cityId,$start,$end]);$blocks=array_flip($blockStmt->fetchAll(PDO::FETCH_COLUMN));
            $update=$this->db->prepare('UPDATE DisciplinasUnidades du JOIN calendario_etapa_disciplinas ed ON ed.id_disciplina=du.idDisciplina SET du.max_atendimentos_disciplina=?,du.dias_disponiveis=?,du.data_inicio=?,du.data_fim=?,du.feriados=? WHERE du.idUnidade=? AND ed.etapa_id=?');
            foreach($stages as $stage){
                $eventStmt->execute([$cityId,$stage['etapa_id'],$start,$end]);$open=array_flip($eventStmt->fetchAll(PDO::FETCH_COLUMN));$closed=[];
                $period=new DatePeriod(new DateTimeImmutable($start),new DateInterval('P1D'),(new DateTimeImmutable($end))->modify('+1 day'));
                foreach($period as $day){$d=$day->format('Y-m-d');if(isset($blocks[$d])||!isset($open[$d]))$closed[]=$d;}
                $update->execute([(int)$stage['vagas'],'"Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"',$start,$end,implode(',',$closed),$unit['id_unidade'],$stage['etapa_id']]);
            }

        }
    }
    private function query(string $sql,array $params): array {$s=$this->db->prepare($sql);$s->execute($params);return $s->fetchAll();}
}
