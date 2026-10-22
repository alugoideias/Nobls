
<?php
require_once __DIR__ . '/env.php';
// --- CONFIGURAÇÃO DO BANCO DE DADOS (PARA PÁGINAS PÚBLICAS, via .env / Railway) ---
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
     $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
     die("Erro de conexão com o banco de dados. Verifique as credenciais.");
}

// Inclui helpers para funções básicas como tradução, se necessário.
require_once 'helpers.php';
