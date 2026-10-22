<?php
/**
 * Script de migração de ESTRUTURA do banco (DDL).
 *
 * Antes, estes ALTER/CREATE TABLE rodavam a cada requisição em index.php,
 * disparando exceptions silenciosas e custo de metadata lock em todo page load.
 * Agora ficam aqui e devem ser executados sob demanda (no deploy).
 *
 * Como rodar:
 *   - CLI (recomendado):  php migrate.php
 *   - Navegador: acesse migrate.php logado como GUARDIÃO ou super admin.
 *
 * É idempotente: pode ser rodado várias vezes com segurança.
 */

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/env.php';

// --- Conexão (reusa as mesmas variáveis de ambiente do app) ---
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: '';
$user = getenv('DB_USER') ?: '';
$pass = getenv('DB_PASS') ?: '';
$dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    http_response_code(500);
    exit('Erro de conexão com o banco de dados.');
}

// --- Autorização: fora do CLI, exige super admin ou Guardião logado ---
if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $allowed = false;
    if (!empty($_SESSION['is_super_admin'])) {
        $allowed = true;
    } elseif (!empty($_SESSION['user_id'])) {
        $stmt = $pdo->prepare("SELECT tipo_perfil FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $allowed = ($stmt->fetchColumn() === 'GUARDIÃO');
    }
    if (!$allowed) {
        http_response_code(403);
        exit('Acesso negado. Faça login como Guardião ou super admin.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$log = [];
function step(&$log, $label, callable $fn) {
    try {
        $fn();
        $log[] = "[OK]    $label";
    } catch (PDOException $e) {
        // Idempotência: "coluna já existe" etc. não é falha real.
        $log[] = "[SKIP]  $label (" . $e->getCode() . ")";
    }
}

// --- ALTER TABLE: colunas incrementais ---
step($log, 'orcamentos.ativo',  fn() => $pdo->exec("ALTER TABLE orcamentos ADD COLUMN ativo BOOLEAN DEFAULT 1"));
step($log, 'orcamentos.escopo', fn() => $pdo->exec("ALTER TABLE orcamentos ADD COLUMN escopo ENUM('FAMILIA','PESSOAL') DEFAULT 'FAMILIA'"));
step($log, 'lancamentos.is_internal_transfer', fn() => $pdo->exec("ALTER TABLE lancamentos ADD COLUMN is_internal_transfer BOOLEAN DEFAULT 0"));
step($log, 'lancamentos.settlement_id', fn() => $pdo->exec("ALTER TABLE lancamentos ADD COLUMN settlement_id VARCHAR(36) DEFAULT NULL"));
step($log, 'lancamentos.is_shared', fn() => $pdo->exec("ALTER TABLE lancamentos ADD COLUMN is_shared BOOLEAN DEFAULT FALSE"));
step($log, 'lancamentos.meta_dados', fn() => $pdo->exec("ALTER TABLE lancamentos ADD COLUMN meta_dados JSON DEFAULT NULL"));
step($log, 'familias.app_config', fn() => $pdo->exec("ALTER TABLE familias ADD COLUMN app_config JSON DEFAULT NULL"));

// --- saldo_inicial + backfill (base para reconciliação de saldo) ---
step($log, 'contas_bancarias.saldo_inicial', function () use ($pdo) {
    $pdo->exec("ALTER TABLE contas_bancarias ADD COLUMN saldo_inicial DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tipo");
    // Backfill só roda logo após criar a coluna (quando o ALTER acima não lançou exceção).
    $pdo->exec("
        UPDATE contas_bancarias c
        LEFT JOIN (
            SELECT conta_id,
                   SUM(CASE WHEN tipo = 'RECEITA' THEN valor ELSE -valor END) AS mov
            FROM lancamentos
            WHERE status = 'PAGO' AND conta_id IS NOT NULL AND deleted_at IS NULL
            GROUP BY conta_id
        ) m ON m.conta_id = c.id
        SET c.saldo_inicial = c.saldo_atual - COALESCE(m.mov, 0)
    ");
});

// --- CREATE TABLE: estruturas novas ---
step($log, 'tabela lancamento_rateios', fn() => $pdo->exec("CREATE TABLE IF NOT EXISTS `lancamento_rateios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `lancamento_id` INT NOT NULL,
  `usuario_id` INT NOT NULL,
  `valor` DECIMAL(10, 2) NOT NULL,
  `porcentagem` DECIMAL(5, 2) DEFAULT NULL,
  FOREIGN KEY (`lancamento_id`) REFERENCES `lancamentos`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"));

step($log, 'tabela mercado_produtos', fn() => $pdo->exec("CREATE TABLE IF NOT EXISTS `mercado_produtos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `familia_id` INT NOT NULL,
  `nome` VARCHAR(255) NOT NULL,
  `categoria` VARCHAR(100) DEFAULT 'Geral',
  `intervalo_medio_dias` INT DEFAULT 0,
  `ultima_compra` DATE DEFAULT NULL,
  `proxima_compra_prevista` DATE DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"));

step($log, 'tabela mercado_historico', fn() => $pdo->exec("CREATE TABLE IF NOT EXISTS `mercado_historico` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `produto_id` INT NOT NULL,
  `data_compra` DATE NOT NULL,
  `quantidade` DECIMAL(10,2) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`produto_id`) REFERENCES `mercado_produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"));

// --- Normalização de dados legados (grupo renomeado) ---
step($log, "categorias grupo Assinaturas -> Serviços", fn() => $pdo->exec("UPDATE categorias SET grupo = 'Serviços' WHERE grupo = 'Assinaturas'"));

$log[] = '';
$log[] = 'Migração concluída.';

echo implode("\n", $log) . "\n";
