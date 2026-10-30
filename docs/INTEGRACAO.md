# Integração PHP/MariaDB

O código foi criado para integrar um sistema de agendamento existente. Não há conexão com esse sistema na demonstração pública.

## Dependências

PHP 8.3 com PDO MySQL e MariaDB. O diretório público do servidor deve ser somente `public/`.
Configure CAL_DB_HOST, CAL_DB_PORT, CAL_DB_NAME, CAL_DB_USER e CAL_DB_PASS no ambiente.
O exemplo aponta para um nome fictício e não contém credenciais válidas.

## Contrato legado

Os serviços dependem de tabelas como `UnidadesEscolares`, `Disciplinas`, `DisciplinasUnidades`, `DiaExtra`, `Agendamentos`, `Turmas`, `TurmasDisciplinas` e `vagasExtrasUnidades`. Consulte as consultas dos serviços para os campos e relações exigidos. O esquema completo e os registros do ambiente de origem não são distribuídos.

A pasta SQL contém o esquema auxiliar e alterações estruturais selecionadas para leitura técnica. Seeds de unidades, disciplinas, usuários e correções específicas foram excluídos. Não execute automaticamente todos os SQLs em um banco existente.

Antes de adaptar a integração: criar banco de desenvolvimento, implementar o contrato legado com dados fictícios, revisar migrações e testar permissões, histórico, concorrência e transações. Integração de envio de e-mails e cancelamentos exige validação separada.
