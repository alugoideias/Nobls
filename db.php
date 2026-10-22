<?php
require_once __DIR__ . '/env.php';
// --- CONFIGURAÇÃO DO BANCO DE DADOS (via .env / Railway) ---
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
     die("Erro de conexão com o banco de dados: " . $e->getMessage());
}

// --- CONTROLE DE SESSÃO E HELPERS ---
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once 'helpers.php';

// Se não houver usuário na sessão, redireciona para o login.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// --- VARIÁVEIS GLOBAIS DA APLICAÇÃO ---
$LOGGED_IN_USER_ID = $_SESSION['user_id'];
$LOGGED_IN_FAMILY_ID = $_SESSION['familia_id'];
$IS_SUPER_ADMIN = is_super_admin();

// Busca os dados completos do usuário logado E AS CONFIGURAÇÕES DA FAMÍLIA (app_config)
// Implementa Self-Healing: Se a coluna não existir, cria e tenta de novo.
try {
    $stmt = $pdo->prepare("SELECT u.*, f.nome_familia, f.moeda, f.idioma, f.app_config FROM usuarios u LEFT JOIN familias f ON u.familia_id = f.id WHERE u.id = ?");
    $stmt->execute([$LOGGED_IN_USER_ID]);
    $CURRENT_USER = $stmt->fetch();
} catch (PDOException $e) {
    // Código 42S22 = Column not found
    if ($e->getCode() == '42S22') {
        $pdo->exec("ALTER TABLE familias ADD COLUMN app_config JSON DEFAULT NULL");
        // Tenta novamente
        $stmt = $pdo->prepare("SELECT u.*, f.nome_familia, f.moeda, f.idioma, f.app_config FROM usuarios u LEFT JOIN familias f ON u.familia_id = f.id WHERE u.id = ?");
        $stmt->execute([$LOGGED_IN_USER_ID]);
        $CURRENT_USER = $stmt->fetch();
    } else {
        throw $e; // Se for outro erro, estoura.
    }
}

if (!$CURRENT_USER) {
    header('Location: logout.php');
    exit;
}

// Garante que o super admin tenha valores padrão para não quebrar a UI
if ($IS_SUPER_ADMIN) {
    $CURRENT_USER['moeda'] = 'BRL';
    $CURRENT_USER['idioma'] = 'pt-BR';
}

// Define o idioma do usuário na sessão para a função _t()
$_SESSION['user_lang'] = $CURRENT_USER['idioma'] ?? 'pt-BR';

$CURRENT_USER['nome_familia'] = $CURRENT_USER['nome_familia'] ?? 'Plataforma Nobl$';
