-- MariaDB 11.4 / utf8mb4
-- Cria somente tabelas auxiliares. Não altera dados legados.

CREATE TABLE IF NOT EXISTS calendario_usuarios (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(255) NOT NULL,
    senha_hash VARCHAR(255) NOT NULL,
    perfil ENUM('leitor','editor','administrador') NOT NULL DEFAULT 'leitor',
    primeiro_acesso TINYINT(1) NOT NULL DEFAULT 1,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    tentativas_login SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    bloqueado_ate DATETIME NULL,
    ultimo_login_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendario_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_tokens (
    id BIGINT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    finalidade ENUM('primeiro_acesso','redefinir_senha','confirmar_senha') NOT NULL,
    token_hash CHAR(64) NOT NULL COMMENT 'SHA-256 do token; nunca guardar token puro',
    expira_em DATETIME NOT NULL,
    usado_em DATETIME NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendario_tokens_hash (token_hash),
    KEY idx_calendario_tokens_usuario (usuario_id, finalidade, usado_em, expira_em),
    CONSTRAINT fk_cal_tokens_usuario FOREIGN KEY (usuario_id)
        REFERENCES calendario_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_cidades (
    id INT NOT NULL AUTO_INCREMENT,
    nome VARCHAR(100) NOT NULL,
    sigla VARCHAR(10) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendario_cidades_nome (nome),
    UNIQUE KEY uq_calendario_cidades_sigla (sigla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma cidade visual pode apontar para várias unidades legadas.
-- Uma cidade pode distinguir unidades gerais e especiais.
CREATE TABLE IF NOT EXISTS calendario_cidade_unidades (
    cidade_id INT NOT NULL,
    id_unidade INT NOT NULL,
    finalidade ENUM('geral','especial') NOT NULL DEFAULT 'geral',
    PRIMARY KEY (cidade_id, id_unidade),
    UNIQUE KEY uq_cal_cidade_unidade_legada (id_unidade),
    CONSTRAINT fk_cal_cidade_unidades_cidade FOREIGN KEY (cidade_id)
        REFERENCES calendario_cidades (id),
    CONSTRAINT fk_cal_cidade_unidades_unidade FOREIGN KEY (id_unidade)
        REFERENCES UnidadesEscolares (idUnidade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_etapas (
    id INT NOT NULL AUTO_INCREMENT,
    nome VARCHAR(120) NOT NULL,
    nome_curto VARCHAR(40) NOT NULL,
    destino_sp ENUM('geral','especial') NOT NULL DEFAULT 'geral',
    cor CHAR(7) NULL,
    ordem SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calendario_etapas_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uma etapa simplificada representa vários idDisciplina.
CREATE TABLE IF NOT EXISTS calendario_etapa_disciplinas (
    etapa_id INT NOT NULL,
    id_disciplina INT NOT NULL,
    PRIMARY KEY (etapa_id, id_disciplina),
    UNIQUE KEY uq_cal_etapa_disciplina_legada (id_disciplina),
    CONSTRAINT fk_cal_etapa_disc_etapa FOREIGN KEY (etapa_id)
        REFERENCES calendario_etapas (id),
    CONSTRAINT fk_cal_etapa_disc_disciplina FOREIGN KEY (id_disciplina)
        REFERENCES Disciplinas (idDisciplina)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Capacidade única por etapa e cidade, replicada nos relacionamentos legados.
CREATE TABLE IF NOT EXISTS calendario_etapa_vagas (
    cidade_id INT NOT NULL,
    etapa_id INT NOT NULL,
    vagas INT UNSIGNED NOT NULL,
    atualizado_por INT NOT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (cidade_id, etapa_id),
    CONSTRAINT chk_cal_etapa_vagas_positiva CHECK (vagas > 0),
    CONSTRAINT fk_cal_vagas_cidade FOREIGN KEY (cidade_id)
        REFERENCES calendario_cidades (id),
    CONSTRAINT fk_cal_vagas_etapa FOREIGN KEY (etapa_id)
        REFERENCES calendario_etapas (id),
    CONSTRAINT fk_cal_vagas_usuario FOREIGN KEY (atualizado_por)
        REFERENCES calendario_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_eventos (
    id BIGINT NOT NULL AUTO_INCREMENT,
    cidade_id INT NOT NULL,
    etapa_id INT NOT NULL,
    data_evento DATE NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por INT NOT NULL,
    atualizado_por INT NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cal_evento (cidade_id, etapa_id, data_evento),
    KEY idx_cal_eventos_mes (cidade_id, data_evento, ativo),
    CONSTRAINT fk_cal_eventos_cidade FOREIGN KEY (cidade_id)
        REFERENCES calendario_cidades (id),
    CONSTRAINT fk_cal_eventos_etapa FOREIGN KEY (etapa_id)
        REFERENCES calendario_etapas (id),
    CONSTRAINT fk_cal_eventos_criado_por FOREIGN KEY (criado_por)
        REFERENCES calendario_usuarios (id),
    CONSTRAINT fk_cal_eventos_atualizado_por FOREIGN KEY (atualizado_por)
        REFERENCES calendario_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_bloqueios (
    id BIGINT NOT NULL AUTO_INCREMENT,
    cidade_id INT NOT NULL,
    data_bloqueio DATE NOT NULL,
    tipo ENUM('feriado','folga','ferias') NOT NULL,
    observacao VARCHAR(255) NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_por INT NOT NULL,
    atualizado_por INT NOT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cal_bloqueio (cidade_id, data_bloqueio),
    KEY idx_cal_bloqueios_mes (cidade_id, data_bloqueio, ativo),
    CONSTRAINT fk_cal_bloqueios_cidade FOREIGN KEY (cidade_id)
        REFERENCES calendario_cidades (id),
    CONSTRAINT fk_cal_bloqueios_criado_por FOREIGN KEY (criado_por)
        REFERENCES calendario_usuarios (id),
    CONSTRAINT fk_cal_bloqueios_atualizado_por FOREIGN KEY (atualizado_por)
        REFERENCES calendario_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_alteracoes (
    id BIGINT NOT NULL AUTO_INCREMENT,
    usuario_id INT NOT NULL,
    cidade_id INT NOT NULL,
    status ENUM('processando','aplicada','falhou') NOT NULL DEFAULT 'processando',
    contem_data_passada TINYINT(1) NOT NULL DEFAULT 0,
    confirmacao_data_passada TINYINT(1) NOT NULL DEFAULT 0,
    motivo_data_passada VARCHAR(500) NULL,
    total_inclusoes INT UNSIGNED NOT NULL DEFAULT 0,
    total_remocoes INT UNSIGNED NOT NULL DEFAULT 0,
    total_cancelamentos INT UNSIGNED NOT NULL DEFAULT 0,
    ip VARBINARY(16) NULL,
    user_agent VARCHAR(500) NULL,
    erro TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    concluido_em DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_cal_alteracoes_cidade_data (cidade_id, criado_em),
    KEY idx_cal_alteracoes_usuario_data (usuario_id, criado_em),
    CONSTRAINT fk_cal_alteracoes_usuario FOREIGN KEY (usuario_id)
        REFERENCES calendario_usuarios (id),
    CONSTRAINT fk_cal_alteracoes_cidade FOREIGN KEY (cidade_id)
        REFERENCES calendario_cidades (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_alteracao_itens (
    id BIGINT NOT NULL AUTO_INCREMENT,
    alteracao_id BIGINT NOT NULL,
    tipo ENUM('evento_adicionado','evento_removido','bloqueio_adicionado',
              'bloqueio_removido','vagas_alteradas','periodo_atualizado',
              'agendamento_cancelado','relacionamento_consolidado') NOT NULL,
    tabela_afetada VARCHAR(64) NOT NULL,
    registro_id VARCHAR(100) NULL,
    valor_anterior LONGTEXT NULL,
    valor_novo LONGTEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cal_alt_itens_alteracao (alteracao_id, id),
    CONSTRAINT fk_cal_alt_itens_alteracao FOREIGN KEY (alteracao_id)
        REFERENCES calendario_alteracoes (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outbox transacional: a linha nasce na mesma transação do cancelamento.
CREATE TABLE IF NOT EXISTS calendario_email_fila (
    id BIGINT NOT NULL AUTO_INCREMENT,
    alteracao_id BIGINT NOT NULL,
    id_agendamento INT NULL,
    destinatario VARCHAR(255) NOT NULL,
    assunto VARCHAR(255) NOT NULL,
    corpo_html MEDIUMTEXT NOT NULL,
    status ENUM('pendente','enviando','enviado','falhou') NOT NULL DEFAULT 'pendente',
    tentativas SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    proxima_tentativa_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ultimo_erro TEXT NULL,
    criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    enviado_em DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_cal_email_processamento (status, proxima_tentativa_em, id),
    KEY idx_cal_email_alteracao (alteracao_id),
    CONSTRAINT fk_cal_email_alteracao FOREIGN KEY (alteracao_id)
        REFERENCES calendario_alteracoes (id),
    CONSTRAINT fk_cal_email_agendamento FOREIGN KEY (id_agendamento)
        REFERENCES Agendamentos (idAgendamento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS calendario_configuracoes (
    chave VARCHAR(100) NOT NULL,
    valor LONGTEXT NOT NULL,
    atualizado_por INT NULL,
    atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (chave),
    CONSTRAINT fk_cal_config_usuario FOREIGN KEY (atualizado_por)
        REFERENCES calendario_usuarios (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO calendario_configuracoes (chave, valor)
VALUES
    ('timezone_aplicacao', 'America/Sao_Paulo'),
    ('status_cancelamento_calendario', 'Cancelado'),
    ('limite_maximo_vagas', '500');
