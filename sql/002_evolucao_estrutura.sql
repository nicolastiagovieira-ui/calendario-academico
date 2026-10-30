-- Referência estrutural; revisar dependências antes de aplicar.

ALTER TABLE calendario_usuarios
    ADD COLUMN IF NOT EXISTS tentativas_login SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER ativo,
    ADD COLUMN IF NOT EXISTS bloqueado_ate DATETIME NULL AFTER tentativas_login,
    ADD COLUMN IF NOT EXISTS ultimo_login_em DATETIME NULL AFTER bloqueado_ate;

ALTER TABLE calendario_etapas
    ADD COLUMN IF NOT EXISTS categoria ENUM('estetica','ozonio','hof')
        NOT NULL DEFAULT 'estetica' AFTER destino_sp;

ALTER TABLE calendario_alteracao_itens
    MODIFY tipo ENUM('evento_adicionado','evento_removido','bloqueio_adicionado',
                     'bloqueio_removido','vagas_alteradas','vagas_extras_alteradas',
                     'periodo_atualizado','agendamento_cancelado',
                     'relacionamento_consolidado','unidade_status_alterado') NOT NULL;

ALTER TABLE calendario_bloqueios
    MODIFY tipo ENUM('feriado','folga','ferias','bloqueado') NOT NULL;

CREATE TABLE IF NOT EXISTS vagasExtrasUnidades (
    idVagaExtraUnidade INT NOT NULL AUTO_INCREMENT,
    idUnidade INT NOT NULL,
    data DATE NOT NULL,
    max_atendimentos INT NOT NULL,
    descricao VARCHAR(255) NULL,
    PRIMARY KEY (idVagaExtraUnidade),
    UNIQUE KEY uq_vagaextraunidade_unidade_data (idUnidade,data),
    KEY idx_vagaextraunidade_data (data),
    CONSTRAINT fk_vagaextraunidade_unidade FOREIGN KEY (idUnidade)
        REFERENCES UnidadesEscolares(idUnidade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE calendario_bloqueios
    MODIFY tipo ENUM('feriado','folga','ferias','recesso','bloqueado','limitado') NOT NULL;

CREATE TABLE IF NOT EXISTS TurmasVagasExtras (
    idTurmaVagaExtra INT NOT NULL AUTO_INCREMENT,
    idTurma INT NOT NULL,
    data DATE NOT NULL,
    max_atendimentos INT UNSIGNED NOT NULL,
    descricao VARCHAR(255) NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (idTurmaVagaExtra),
    UNIQUE KEY uq_turmavagaextra_turma_data (idTurma, data),
    KEY idx_turmavagaextra_data (data),

    CONSTRAINT fk_turmavagaextra_turma
        FOREIGN KEY (idTurma)
        REFERENCES Turmas (idTurma)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Limites excepcionais de vagas de uma turma/sala por dia';
