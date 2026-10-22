
<?php
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    die("Acesso negado. Faça login primeiro.");
}

$queries = [];
$messages = [];

// 1. Tabelas Pets (Mantidas)
$queries[] = "CREATE TABLE IF NOT EXISTS `pets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `familia_id` INT NOT NULL,
  `nome` VARCHAR(255) NOT NULL,
  `raca` VARCHAR(100) DEFAULT NULL,
  `data_nascimento` DATE DEFAULT NULL,
  `peso` DECIMAL(5, 2) DEFAULT NULL,
  `foto` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

$queries[] = "CREATE TABLE IF NOT EXISTS `pet_vacinacoes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pet_id` INT NOT NULL,
  `nome_vacina` VARCHAR(255) NOT NULL,
  `data_aplicacao` DATE NOT NULL,
  `proxima_dose` DATE DEFAULT NULL,
  `custo` DECIMAL(10, 2) DEFAULT 0.00,
  `recorrencia` ENUM('UNICA', 'MENSAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL') DEFAULT 'UNICA',
  `lancamento_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`pet_id`) REFERENCES `pets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lancamento_id`) REFERENCES `lancamentos`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

// 2. Colunas de Veículos
try {
    $pdo->exec("ALTER TABLE `ativos_veiculos` ADD COLUMN `placa` VARCHAR(20) DEFAULT NULL AFTER `valor_estimado`");
    $pdo->exec("ALTER TABLE `ativos_veiculos` ADD COLUMN `renavam` VARCHAR(30) DEFAULT NULL AFTER `placa`");
} catch (PDOException $e) {}

// --- SISTEMA HÍBRIDO E PRIVACIDADE ---
try {
    $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `is_restricted` BOOLEAN DEFAULT FALSE AFTER `tipo_perfil`");
} catch (PDOException $e) { /* Ignora se já existe */ }

try {
    $pdo->exec("ALTER TABLE `lancamentos` ADD COLUMN `is_shared` BOOLEAN DEFAULT FALSE AFTER `origem_lancamento`");
} catch (PDOException $e) { /* Ignora se já existe */ }


// --- NOVAS CATEGORIAS DETALHADAS ---
$new_categories = [
    // Veículos
    ['nome' => 'Combustível', 'grupo' => 'Veículos', 'icone' => 'fa-gas-pump'],
    ['nome' => 'Manutenção/Mecânica', 'grupo' => 'Veículos', 'icone' => 'fa-wrench'],
    ['nome' => 'Seguro Auto', 'grupo' => 'Veículos', 'icone' => 'fa-shield-car'],
    ['nome' => 'Impostos (IPVA)', 'grupo' => 'Veículos', 'icone' => 'fa-file-invoice-dollar'],
    ['nome' => 'Estacionamento/Pedágio', 'grupo' => 'Veículos', 'icone' => 'fa-parking'],
    ['nome' => 'Limpeza/Lava-jato', 'grupo' => 'Veículos', 'icone' => 'fa-soap'],
    ['nome' => 'Multas', 'grupo' => 'Veículos', 'icone' => 'fa-triangle-exclamation'],
    
    // Pets
    ['nome' => 'Alimentação Pet', 'grupo' => 'Pets', 'icone' => 'fa-bone'],
    ['nome' => 'Veterinário', 'grupo' => 'Pets', 'icone' => 'fa-user-doctor'],
    ['nome' => 'Vacinas/Medicamentos', 'grupo' => 'Pets', 'icone' => 'fa-syringe'],
    ['nome' => 'Banho e Tosa', 'grupo' => 'Pets', 'icone' => 'fa-scissors'],
    ['nome' => 'Acessórios/Brinquedos', 'grupo' => 'Pets', 'icone' => 'fa-baseball'],
    ['nome' => 'Hotel/Creche', 'grupo' => 'Pets', 'icone' => 'fa-hotel']
];

$stmtCheckCat = $pdo->prepare("SELECT COUNT(*) FROM categorias WHERE familia_id = ? AND nome = ? AND grupo = ?");
$stmtInsertCat = $pdo->prepare("INSERT INTO categorias (familia_id, nome, tipo, grupo, icone) VALUES (?, ?, 'DESPESA', ?, ?)");

$added_count = 0;
foreach ($new_categories as $cat) {
    $stmtCheckCat->execute([$LOGGED_IN_FAMILY_ID, $cat['nome'], $cat['grupo']]);
    if ($stmtCheckCat->fetchColumn() == 0) {
        $stmtInsertCat->execute([$LOGGED_IN_FAMILY_ID, $cat['nome'], $cat['grupo'], $cat['icone']]);
        $added_count++;
    }
}

if ($added_count > 0) {
    $messages[] = "$added_count novas categorias (Veículos/Pets) adicionadas.";
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Atualização do Sistema</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen">
    <div class="bg-white p-8 rounded-2xl shadow-xl max-w-lg w-full">
        <div class="text-center mb-6">
            <div class="w-16 h-16 bg-indigo-100 text-indigo-600 rounded-full flex items-center justify-center text-2xl mx-auto mb-4">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800">Sistema Atualizado</h1>
            <p class="text-slate-500">Novas categorias e tabelas criadas com sucesso.</p>
        </div>

        <div class="space-y-3 mb-6">
            <?php
            foreach ($queries as $sql) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {}
            }
            
            if (empty($messages)) {
                echo '<div class="flex items-center gap-3 text-sm text-slate-500 bg-slate-100 p-3 rounded-lg"><i class="fa-solid fa-check-circle"></i> Tudo certo. Seu sistema já estava atualizado.</div>';
            }

            foreach ($messages as $msg) {
                 echo '<div class="flex items-center gap-3 text-sm text-emerald-700 bg-emerald-50 p-3 rounded-lg"><i class="fa-solid fa-plus-circle"></i> ' . $msg . '</div>';
            }
            ?>
        </div>

        <a href="index.php?page=dashboard" class="block w-full py-3 bg-indigo-600 text-white text-center rounded-xl font-bold hover:bg-indigo-700 transition-colors">
            VOLTAR AO DASHBOARD
        </a>
    </div>
</body>
</html>
