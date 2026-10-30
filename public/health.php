<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Calendario\Database;

header('Content-Type: application/json; charset=utf-8');
try {
    $db = Database::connection();
    $tables = (int) $db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name LIKE 'calendario\\_%'")->fetchColumn();
    echo json_encode(['ok'=>true,'php'=>PHP_VERSION,'banco'=>true,'tabelasCalendario'=>$tables],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok'=>false,'banco'=>false,'erro'=>'Configuração ou conexão indisponível.'],JSON_UNESCAPED_UNICODE);
}
