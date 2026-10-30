<?php
declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

use Calendario\Database;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$name = $argv[1] ?? '';
if (!preg_match('/^\d{3}_[a-z0-9_]+\.sql$/', $name)) {
    fwrite(STDERR, "Uso: php bin/migrar.php 011_nome.sql\n");
    exit(2);
}
$path = realpath(__DIR__ . '/../sql/' . $name);
$sqlDir = realpath(__DIR__ . '/../sql');
if (!$path || !$sqlDir || !str_starts_with($path, $sqlDir . DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Migração não encontrada.\n");
    exit(2);
}

$db = Database::connection();
$sql = file_get_contents($path);
if ($sql === false) throw new RuntimeException('Não foi possível ler a migração.');
$db->exec($sql);
echo "Migração aplicada: {$name}\n";
