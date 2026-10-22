<?php
require_once 'db.php';

// --- LÓGICA DE TOGGLE ADMIN (MODO GOD) ---
// Só contas elegíveis (marcadas no login) podem alternar o modo super admin.
if (isset($_GET['toggle_admin'])) {
    if (!empty($_SESSION['is_admin_account'])) {
         $_SESSION['is_super_admin'] = !($_SESSION['is_super_admin'] ?? false);
    }
    header('Location: index.php');
    exit;
}

// --- HELPER PARA LIMPEZA DE MOEDA (BRL -> FLOAT) ---
function clean_money_input($val) {
    if ($val === null || $val === '') return 0.00;

    // Se já vier numérico (ex.: valores da IA na importação), retorna direto.
    if (is_int($val) || is_float($val)) return (float)$val;

    $val = trim((string)$val);
    if ($val === '') return 0.00;

    $hasComma = strpos($val, ',') !== false;
    $hasDot   = strpos($val, '.') !== false;

    if ($hasComma) {
        // Formato brasileiro: vírgula é decimal, ponto é separador de milhar.
        // Ex.: "1.234,56" -> 1234.56
        $val = str_replace('.', '', $val);
        $val = str_replace(',', '.', $val);
    } elseif ($hasDot) {
        // Só ponto, sem vírgula. Precisa decidir se o ponto é milhar ou decimal.
        // Heurística: se o último grupo após um ponto tem exatamente 3 dígitos
        // (e há mais de um grupo ou o número começa com grupo de milhar),
        // tratamos como separador de milhar. Caso contrário, é decimal.
        $parts = explode('.', $val);
        $last = end($parts);
        if (count($parts) > 1 && strlen($last) === 3 && ctype_digit($last)) {
            // Ex.: "1.000", "12.000", "1.234.000" -> milhar
            $val = str_replace('.', '', $val);
        }
        // Senão, mantém o ponto como decimal. Ex.: "15.90", "1500.5" -> inalterado
    }

    // Remove qualquer caractere remanescente que não seja dígito, ponto ou sinal.
    $val = preg_replace('/[^0-9.\-]/', '', $val);

    return (float)$val;
}

// --- SEEDER E MIGRAÇÕES ---
if (isset($LOGGED_IN_FAMILY_ID) && $LOGGED_IN_FAMILY_ID > 0) {
    $pdo->query("UPDATE categorias SET grupo = 'Serviços' WHERE grupo = 'Assinaturas' AND familia_id = $LOGGED_IN_FAMILY_ID");

    // Migração: Orçamentos e Rateios
    try {
        $pdo->exec("ALTER TABLE orcamentos ADD COLUMN ativo BOOLEAN DEFAULT 1");
        $pdo->exec("ALTER TABLE orcamentos ADD COLUMN escopo ENUM('FAMILIA', 'PESSOAL') DEFAULT 'FAMILIA'");
    } catch (PDOException $e) {}

    try {
        $pdo->exec("ALTER TABLE lancamentos ADD COLUMN is_internal_transfer BOOLEAN DEFAULT 0");
        $pdo->exec("ALTER TABLE lancamentos ADD COLUMN settlement_id VARCHAR(36) DEFAULT NULL");
        $pdo->exec("ALTER TABLE lancamentos ADD COLUMN is_shared BOOLEAN DEFAULT FALSE");
    } catch (PDOException $e) {}

    // Migração: Configurações do App (Módulos e Aparência) - Redundância caso o db.php não tenha pego
    try {
        $pdo->exec("ALTER TABLE familias ADD COLUMN app_config JSON DEFAULT NULL");
    } catch (PDOException $e) {}
    
    // Migração: Metadados para Lançamentos (Para Km/Litros de combustível)
    try {
        $pdo->exec("ALTER TABLE lancamentos ADD COLUMN meta_dados JSON DEFAULT NULL");
    } catch (PDOException $e) {}

    // Migração: saldo_inicial em contas (base para reconciliação de saldo).
    // Para contas legadas, inicializa de forma que a reconciliação reproduza
    // o saldo_atual vigente: saldo_inicial = saldo_atual - (receitas - despesas) pagas.
    try {
        $pdo->exec("ALTER TABLE contas_bancarias ADD COLUMN saldo_inicial DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tipo");
        // Só roda o backfill quando a coluna acabou de ser criada (evita refazer a cada request).
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
    } catch (PDOException $e) {}

    // NOVA TABELA DE RATEIO
    $pdo->exec("CREATE TABLE IF NOT EXISTS `lancamento_rateios` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `lancamento_id` INT NOT NULL,
      `usuario_id` INT NOT NULL,
      `valor` DECIMAL(10, 2) NOT NULL,
      `porcentagem` DECIMAL(5, 2) DEFAULT NULL,
      FOREIGN KEY (`lancamento_id`) REFERENCES `lancamentos`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    
    // NOVAS TABELAS: MERCADO / DESPENSA
    $pdo->exec("CREATE TABLE IF NOT EXISTS `mercado_produtos` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `mercado_historico` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `produto_id` INT NOT NULL,
      `data_compra` DATE NOT NULL,
      `quantidade` DECIMAL(10,2) DEFAULT 1,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`produto_id`) REFERENCES `mercado_produtos`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Garante Categorias Essenciais
    $catsServicos = ['Streaming', 'Programas', 'Saúde', 'Educação', 'Internet/TV'];
    $stmtCheck = $pdo->prepare("SELECT nome FROM categorias WHERE familia_id = ?");
    $stmtCheck->execute([$LOGGED_IN_FAMILY_ID]);
    $existingCats = $stmtCheck->fetchAll(PDO::FETCH_COLUMN);
    
    $stmtInsert = $pdo->prepare("INSERT INTO categorias (familia_id, nome, tipo, grupo, icone) VALUES (?, ?, 'DESPESA', ?, ?)");
    
    $missingCats = array_diff($catsServicos, $existingCats);
    if (!empty($missingCats)) {
        foreach ($missingCats as $catName) { $stmtInsert->execute([$LOGGED_IN_FAMILY_ID, $catName, 'Serviços', 'fa-rss']); }
    }

    $newSpecialCats = [
        ['nome' => 'Combustível', 'grupo' => 'Veículos', 'icone' => 'fa-gas-pump'],
        ['nome' => 'Manutenção/Mecânica', 'grupo' => 'Veículos', 'icone' => 'fa-wrench'],
        ['nome' => 'Seguro Auto', 'grupo' => 'Veículos', 'icone' => 'fa-shield-car'],
        ['nome' => 'Impostos (IPVA)', 'grupo' => 'Veículos', 'icone' => 'fa-file-invoice-dollar'],
        ['nome' => 'Estacionamento/Pedágio', 'grupo' => 'Veículos', 'icone' => 'fa-parking'],
        ['nome' => 'Limpeza/Lava-jato', 'grupo' => 'Veículos', 'icone' => 'fa-soap'],
        ['nome' => 'Multas', 'grupo' => 'Veículos', 'icone' => 'fa-triangle-exclamation'],
        ['nome' => 'Alimentação Pet', 'grupo' => 'Pets', 'icone' => 'fa-bone'],
        ['nome' => 'Veterinário', 'grupo' => 'Pets', 'icone' => 'fa-user-doctor'],
        ['nome' => 'Vacinas/Medicamentos', 'grupo' => 'Pets', 'icone' => 'fa-syringe'],
        ['nome' => 'Banho e Tosa', 'grupo' => 'Pets', 'icone' => 'fa-scissors'],
        ['nome' => 'Acessórios/Brinquedos', 'grupo' => 'Pets', 'icone' => 'fa-baseball'],
        ['nome' => 'Hotel/Creche', 'grupo' => 'Pets', 'icone' => 'fa-hotel'],
        ['nome' => 'Acerto Interno', 'grupo' => 'Transferências', 'icone' => 'fa-money-bill-transfer']
    ];

    foreach ($newSpecialCats as $cat) {
        if (!in_array($cat['nome'], $existingCats)) {
             $stmtInsert->execute([$LOGGED_IN_FAMILY_ID, $cat['nome'], $cat['grupo'], $cat['icone']]);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf(); // Protege TODAS as ações POST contra CSRF
    $action = $_POST['action'] ?? '';
    $redirect_page = $_POST['redirect_to'] ?? ''; 

    // --- NOVA AÇÃO: SALVAR CONFIGURAÇÕES DO APP ---
    if ($action === 'update_app_settings') {
        if ($CURRENT_USER['tipo_perfil'] !== 'GUARDIÃO' && !$IS_SUPER_ADMIN) {
            header('Location: index.php?page=dashboard&error=permission_denied'); exit;
        }

        $modules = [
            'budget' => isset($_POST['mod_budget']),
            'goals' => isset($_POST['mod_goals']),
            'pets' => isset($_POST['mod_pets']),
            'gamification' => isset($_POST['mod_gamification']),
            'reports' => isset($_POST['mod_reports']),
            'subscriptions' => isset($_POST['mod_subscriptions']),
            'market' => isset($_POST['mod_market']) // Novo Módulo Mercado
        ];

        $style = [
            'color' => $_POST['theme_color'] ?? 'indigo',
            'icon' => $_POST['family_icon'] ?? 'fa-crown'
        ];

        $config = json_encode(['modules' => $modules, 'style' => $style]);

        $stmt = $pdo->prepare("UPDATE familias SET app_config = ? WHERE id = ?");
        $stmt->execute([$config, $LOGGED_IN_FAMILY_ID]);

        header('Location: index.php?page=settings&success=1'); 
        exit;
    }
    
    // --- MÓDULO MERCADO: Adicionar Produto ---
    if ($action === 'add_market_product') {
        $nome = $_POST['nome'];
        $categoria = $_POST['categoria'] ?? 'Geral';
        $stmt = $pdo->prepare("INSERT INTO mercado_produtos (familia_id, nome, categoria) VALUES (?, ?, ?)");
        $stmt->execute([$LOGGED_IN_FAMILY_ID, $nome, $categoria]);
        header('Location: index.php?page=market&success=1'); exit;
    }
    
    // --- MÓDULO MERCADO: Registrar Compra (Log de Consumo) ---
    if ($action === 'log_market_purchase') {
        $produto_id = $_POST['produto_id'];
        $data = $_POST['data_compra'] ?? date('Y-m-d');
        
        $pdo->beginTransaction();
        try {
            // 1. Registra no histórico
            $stmtHist = $pdo->prepare("INSERT INTO mercado_historico (produto_id, data_compra) VALUES (?, ?)");
            $stmtHist->execute([$produto_id, $data]);
            
            // 2. Recalcula média de dias e prevê próxima compra
            $stmtCalc = $pdo->prepare("SELECT data_compra FROM mercado_historico WHERE produto_id = ? ORDER BY data_compra ASC");
            $stmtCalc->execute([$produto_id]);
            $dates = $stmtCalc->fetchAll(PDO::FETCH_COLUMN);
            
            $intervalo = 0;
            if (count($dates) >= 2) {
                $diffs = [];
                for ($i = 1; $i < count($dates); $i++) {
                    $d1 = new DateTime($dates[$i-1]);
                    $d2 = new DateTime($dates[$i]);
                    $diffs[] = $d1->diff($d2)->days;
                }
                $intervalo = array_sum($diffs) / count($diffs);
            } else {
                $intervalo = 7; // Padrão se não tiver histórico suficiente
            }
            
            $proxima = date('Y-m-d', strtotime("+$intervalo days", strtotime($data)));
            
            // 3. Atualiza produto
            $stmtUpd = $pdo->prepare("UPDATE mercado_produtos SET ultima_compra = ?, intervalo_medio_dias = ?, proxima_compra_prevista = ? WHERE id = ?");
            $stmtUpd->execute([$data, round($intervalo), $proxima, $produto_id]);
            
            $pdo->commit();
            header('Location: index.php?page=market&success=1'); exit;
        } catch (Exception $e) {
            $pdo->rollBack(); header('Location: index.php?page=market&error=1'); exit;
        }
    }
    
    // --- MÓDULO MERCADO: Excluir Produto ---
    if ($action === 'delete_market_product') {
        $stmt = $pdo->prepare("UPDATE mercado_produtos SET deleted_at = NOW() WHERE id = ? AND familia_id = ?");
        $stmt->execute([$_POST['id'], $LOGGED_IN_FAMILY_ID]);
        header('Location: index.php?page=market&success=1'); exit;
    }

    // --- MÓDULO 2: ACERTOS INTERNOS (Criação de Cobrança) ---
    if ($action === 'add_settlement') {
        // ... (código existente mantido)
        $devedor_id = $_POST['devedor_id'];
        $valor = clean_money_input($_POST['valor']);
        $descricao = $_POST['descricao'];
        $data_vencimento = $_POST['data_vencimento'];
        $credor_id = $LOGGED_IN_USER_ID; 

        if ($valor > 0 && $devedor_id) {
            $pdo->beginTransaction();
            try {
                $settlement_id = uniqid('set_');
                $stmtCat = $pdo->prepare("SELECT id FROM categorias WHERE familia_id = ? AND nome = 'Acerto Interno'");
                $stmtCat->execute([$LOGGED_IN_FAMILY_ID]);
                $catId = $stmtCat->fetchColumn();
                
                $stmtCred = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, tipo_recorrencia, is_internal_transfer, settlement_id) VALUES (?, ?, ?, ?, ?, 'RECEITA', ?, 'PENDENTE', ?, 'UNICA', 1, ?)");
                $stmtCred->execute([$LOGGED_IN_FAMILY_ID, $credor_id, $credor_id, "A receber: $descricao", $valor, $data_vencimento, $catId, $settlement_id]);

                $stmtDeb = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, tipo_recorrencia, is_internal_transfer, settlement_id) VALUES (?, ?, ?, ?, ?, 'DESPESA', ?, 'PENDENTE', ?, 'UNICA', 1, ?)");
                $stmtDeb->execute([$LOGGED_IN_FAMILY_ID, $devedor_id, $credor_id, "Pagar a " . $CURRENT_USER['nome'] . ": $descricao", $valor, $data_vencimento, $catId, $settlement_id]);

                $pdo->commit();
                header('Location: index.php?page=transactions&success=1&msg=Cobrança enviada com sucesso.'); exit;
            } catch (Exception $e) {
                $pdo->rollBack(); header('Location: index.php?page=transactions&error=1'); exit;
            }
        }
    }

    if ($action === 'pay_settlement') {
        $settlement_id = $_POST['settlement_id'];
        $conta_origem_id = $_POST['conta_id'] ?: null;

        if ($settlement_id) {
            // --- VALIDAÇÕES PRÉVIAS (antes de mexer em qualquer saldo) ---
            // 1. Conta de origem é obrigatória e precisa ser da família.
            if (!$conta_origem_id) {
                header('Location: index.php?page=dashboard&error=1&msg=' . urlencode('Selecione a conta de onde o valor vai sair.')); exit;
            }
            $stmtChkConta = $pdo->prepare("SELECT 1 FROM contas_bancarias WHERE id = ? AND familia_id = ? AND deleted_at IS NULL");
            $stmtChkConta->execute([$conta_origem_id, $LOGGED_IN_FAMILY_ID]);
            if (!$stmtChkConta->fetchColumn()) {
                header('Location: index.php?page=dashboard&error=permission_denied'); exit;
            }

            // 2. O credor precisa ter conta de recebimento definida, senão o crédito
            //    não seria aplicado e o saldo divergiria. Bloqueia antes de debitar.
            $stmtRec = $pdo->prepare("SELECT id, usuario_id, valor FROM lancamentos WHERE settlement_id = ? AND tipo = 'RECEITA' AND familia_id = ? LIMIT 1");
            $stmtRec->execute([$settlement_id, $LOGGED_IN_FAMILY_ID]);
            $recTx = $stmtRec->fetch();

            $defAcc = null;
            if ($recTx) {
                $stmtUser = $pdo->prepare("SELECT conta_recebimento_id FROM usuarios WHERE id = ?");
                $stmtUser->execute([$recTx['usuario_id']]);
                $defAcc = $stmtUser->fetchColumn();
                if (!$defAcc) {
                    header('Location: index.php?page=dashboard&error=no_credor_account&msg=' . urlencode('O credor precisa definir uma conta de recebimento no perfil antes de receber o acerto.')); exit;
                }
            }

            $pdo->beginTransaction();
            try {
                $stmtVal = $pdo->prepare("SELECT valor FROM lancamentos WHERE settlement_id = ? AND tipo = 'DESPESA' AND familia_id = ? LIMIT 1");
                $stmtVal->execute([$settlement_id, $LOGGED_IN_FAMILY_ID]);
                $val = $stmtVal->fetchColumn();

                $stmtUpd1 = $pdo->prepare("UPDATE lancamentos SET status = 'PAGO', conta_id = ? WHERE settlement_id = ? AND tipo = 'DESPESA' AND familia_id = ?");
                $stmtUpd1->execute([$conta_origem_id, $settlement_id, $LOGGED_IN_FAMILY_ID]);

                if ($recTx && $defAcc) {
                    $pdo->prepare("UPDATE lancamentos SET status = 'PAGO', conta_id = ? WHERE id = ?")->execute([$defAcc, $recTx['id']]);
                    $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id = ?")->execute([$recTx['valor'], $defAcc]);
                }

                if ($val > 0 && $conta_origem_id) {
                    $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual - ? WHERE id = ?")->execute([$val, $conta_origem_id]);
                }

                $pdo->commit();
                header('Location: index.php?page=dashboard&success=1&msg=Acerto realizado e saldos atualizados!'); exit;
            } catch (Exception $e) {
                $pdo->rollBack(); header('Location: index.php?page=dashboard&error=1'); exit;
            }
        }
    }

    if ($action === 'confirm_import') {
        if (!isset($_POST['items']) || !is_array($_POST['items'])) { header('Location: index.php?page=import&error=no_items'); exit; }

        // TRAVA DE REPROCESSAMENTO: só confirma se houver staging ativo na sessão.
        // Um refresh / voltar no navegador reenviaria o POST, mas o staging já foi
        // consumido (unset ao final), então a segunda confirmação é barrada aqui.
        if (!isset($_SESSION['import_staging'])) {
            header('Location: index.php?page=transactions&error=already_imported&msg=' . urlencode('Esta importação já foi processada.')); exit;
        }

        $conta_id = $_POST['conta_id'] ?: null; $cartao_id = $_POST['cartao_id'] ?: null; $count = 0; $skipped = 0;
        $pdo->beginTransaction();
        try {
            // Checagem de duplicata no servidor (não confia só no preview visual).
            $stmtDup = $pdo->prepare("SELECT id FROM lancamentos WHERE familia_id = ? AND data_competencia = ? AND valor = ? AND descricao LIKE ? AND deleted_at IS NULL LIMIT 1");
            $stmtInsert = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, cartao_id, tipo_recorrencia, parcela_atual, parcela_total, recorrencia_grupo_id, origem_lancamento) VALUES (?, ?, ?, ?, ?, ?, ?, 'PAGO', ?, ?, ?, ?, ?, ?, ?, 'IMPORTACAO')");
            foreach ($_POST['items'] as $index => $item) {
                if (isset($item['ignore']) && $item['ignore'] == '1') continue;
                $descricao = $item['descricao']; 
                $valor = clean_money_input($item['valor']); 
                $data = $item['data']; $tipo = $item['tipo']; $catId = !empty($item['categoria_id']) ? $item['categoria_id'] : null;
                $parcela_atual = !empty($item['parcela_atual']) ? $item['parcela_atual'] : null; $parcela_total = !empty($item['parcela_total']) ? $item['parcela_total'] : null;
                $tipo_recorrencia = ($parcela_total > 1) ? 'PARCELADO' : 'UNICA'; $recorrencia_grupo_id = ($parcela_total > 1) ? uniqid() : null;

                // Pula se um lançamento equivalente já existe (evita débito duplicado no saldo).
                $descPart = substr($descricao, 0, 10);
                $stmtDup->execute([$LOGGED_IN_FAMILY_ID, $data, $valor, "%$descPart%"]);
                if ($stmtDup->fetch()) { $skipped++; continue; }

                $stmtInsert->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $descricao, $valor, $tipo, $data, $catId, $conta_id, $cartao_id, $tipo_recorrencia, $parcela_atual, $parcela_total, $recorrencia_grupo_id]);
                $count++;
                
                if ($conta_id && !$cartao_id) {
                    $op = ($tipo === 'RECEITA') ? '+' : '-';
                    $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual $op ? WHERE id = ?")->execute([$valor, $conta_id]);
                }

                if (isset($item['generate_future']) && $item['generate_future'] == '1' && $parcela_total > $parcela_atual) {
                    for ($p = $parcela_atual + 1; $p <= $parcela_total; $p++) {
                        $meses_a_somar = $p - $parcela_atual; $data_futura = date('Y-m-d', strtotime("+$meses_a_somar months", strtotime($data)));
                        $stmtInsertStatus = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, cartao_id, tipo_recorrencia, parcela_atual, parcela_total, recorrencia_grupo_id, origem_lancamento) VALUES (?, ?, ?, ?, ?, ?, ?, 'PENDENTE', ?, ?, ?, ?, ?, ?, ?, 'IMPORTACAO')");
                        $stmtInsertStatus->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $descricao, $valor, $tipo, $data_futura, $catId, $conta_id, $cartao_id, 'PARCELADO', $p, $parcela_total, $recorrencia_grupo_id]);
                    }
                }
            }
            $pdo->commit();
            unset($_SESSION['import_staging']); // consome o staging: impede reprocessamento
            $msg = "$count lançamentos importados.";
            if ($skipped > 0) { $msg .= " $skipped ignorados por já existirem."; }
            header("Location: index.php?page=transactions&success=1&msg=" . urlencode($msg)); exit;
        } catch (Exception $e) { $pdo->rollBack(); header('Location: index.php?page=import&error=db_error'); exit; }
    }

    // --- AÇÕES DE LANÇAMENTOS (Com suporte a Km/Litros) ---
    if ($action === 'add_transaction' || $action === 'edit_transaction') {
        $descricao = $_POST['descricao'];
        $valor = clean_money_input($_POST['valor']); 
        $tipo = $_POST['tipo'];
        $data_comp = $_POST['data_competencia'];
        $status = $_POST['status'];
        $categoria_id = $_POST['categoria_id'];
        $conta_id = !empty($_POST['conta_id']) ? $_POST['conta_id'] : null;
        $cartao_id = !empty($_POST['cartao_id']) ? $_POST['cartao_id'] : null;
        $veiculo_id = !empty($_POST['veiculo_id']) ? $_POST['veiculo_id'] : null;
        $residencia_id = !empty($_POST['residencia_id']) ? $_POST['residencia_id'] : null;
        $recorrencia = $_POST['recorrencia'] ?? 'UNICA'; 
        $qtd_parcelas = $_POST['qtd_parcelas'] ?? 1;
        
        $rateio = isset($_POST['rateio']) ? json_decode($_POST['rateio'], true) : [];
        $manual_shared = isset($_POST['is_shared']) ? 1 : 0;
        $is_shared = (count($rateio) > 0 || $manual_shared) ? 1 : 0;
        
        // --- PROCESSAMENTO DE METADADOS (Combustível) ---
        $meta_dados = null;
        if (isset($_POST['veiculo_km']) || isset($_POST['veiculo_litros'])) {
            $meta_dados = json_encode([
                'veiculo_km' => $_POST['veiculo_km'] ?? null,
                'veiculo_litros' => $_POST['veiculo_litros'] ? str_replace(',', '.', $_POST['veiculo_litros']) : null
            ]);
        }

        if ($tipo === 'RECEITA') {
            $cartao_id = null;
        }

        if ($cartao_id && $tipo === 'DESPESA') {
            $stmtCard = $pdo->prepare("SELECT dia_fechamento, dia_vencimento FROM cartoes_credito WHERE id = ?");
            $stmtCard->execute([$cartao_id]);
            $cardInfo = $stmtCard->fetch();

            if ($cardInfo) {
                $conta_id = null;
                $status = 'PENDENTE';

                $purchaseDate = new DateTime($data_comp);
                $closingDay = (int)$cardInfo['dia_fechamento'];
                $dueDay = (int)$cardInfo['dia_vencimento'];
                $purchaseDay = (int)$purchaseDate->format('d');

                $invoiceMonthDate = clone $purchaseDate;
                if ($purchaseDay >= $closingDay) {
                    $invoiceMonthDate->modify('+1 month');
                }
                
                if ($dueDay > $closingDay) {
                    $targetYear = $invoiceMonthDate->format('Y');
                    $targetMonth = $invoiceMonthDate->format('m');
                } else {
                    $invoiceMonthDate->modify('+1 month');
                    $targetYear = $invoiceMonthDate->format('Y');
                    $targetMonth = $invoiceMonthDate->format('m');
                }
                
                $data_comp = "$targetYear-$targetMonth-" . str_pad($dueDay, 2, '0', STR_PAD_LEFT);
            }
        }

        $pdo->beginTransaction();
        try {
            $lancamento_ids = [];

            if ($action === 'add_transaction') {
                if ($recorrencia === 'PARCELADO' && $qtd_parcelas > 1) {
                    $recorrencia_grupo_id = uniqid(); 
                    for ($i = 1; $i <= $qtd_parcelas; $i++) {
                        $data_parcela = date('Y-m-d', strtotime("+" . ($i - 1) . " months", strtotime($data_comp)));
                        $descricao_parcela = "$descricao ($i/$qtd_parcelas)";
                        $status_item = ($i === 1) ? $status : 'PENDENTE';
                        
                        $stmt = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, cartao_id, veiculo_id, residencia_id, tipo_recorrencia, parcela_atual, parcela_total, recorrencia_grupo_id, is_shared, meta_dados) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'UNICA', ?, ?, ?, ?, ?)");
                        $stmt->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $descricao_parcela, $valor / $qtd_parcelas, $tipo, $data_parcela, $status_item, $categoria_id, $conta_id, $cartao_id, $veiculo_id, $residencia_id, $i, $qtd_parcelas, $recorrencia_grupo_id, $is_shared, $meta_dados]);
                        $lancamento_ids[] = $pdo->lastInsertId();

                        if ($i === 1 && $status_item === 'PAGO' && $conta_id) {
                            $op = ($tipo === 'RECEITA') ? '+' : '-';
                            $valParcela = $valor / $qtd_parcelas;
                            $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual $op ? WHERE id = ?")->execute([$valParcela, $conta_id]);
                        }
                    }
                } elseif ($recorrencia === 'ANUAL') {
                    $recorrencia_grupo_id = uniqid();
                    for ($i = 0; $i < 3; $i++) {
                        $data_futura = date('Y-m-d', strtotime("+$i years", strtotime($data_comp)));
                        $status_item = ($i === 0) ? $status : 'PENDENTE'; 
                        $stmt = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, cartao_id, veiculo_id, residencia_id, tipo_recorrencia, recorrencia_grupo_id, is_shared, meta_dados) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ANUAL', ?, ?, ?)");
                        $stmt->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $descricao, $valor, $tipo, $data_futura, $status_item, $categoria_id, $conta_id, $cartao_id, $veiculo_id, $residencia_id, $recorrencia_grupo_id, $is_shared, $meta_dados]);
                        $lancamento_ids[] = $pdo->lastInsertId();

                        if ($i === 0 && $status_item === 'PAGO' && $conta_id) {
                            $op = ($tipo === 'RECEITA') ? '+' : '-';
                            $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual $op ? WHERE id = ?")->execute([$valor, $conta_id]);
                        }
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, cartao_id, veiculo_id, residencia_id, tipo_recorrencia, is_shared, meta_dados) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $descricao, $valor, $tipo, $data_comp, $status, $categoria_id, $conta_id, $cartao_id, $veiculo_id, $residencia_id, $recorrencia, $is_shared, $meta_dados]);
                    $lancamento_ids[] = $pdo->lastInsertId();

                    if ($status === 'PAGO' && $conta_id) {
                        $op = ($tipo === 'RECEITA') ? '+' : '-';
                        $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual $op ? WHERE id = ?")->execute([$valor, $conta_id]);
                    }
                }
            } else {
                $id = $_POST['transaction_id'];
                
                $stmtOld = $pdo->prepare("SELECT * FROM lancamentos WHERE id = ? AND familia_id = ?");
                $stmtOld->execute([$id, $LOGGED_IN_FAMILY_ID]);
                $oldTx = $stmtOld->fetch();
                
                if ($oldTx) {
                    if ($oldTx['status'] === 'PAGO' && $oldTx['conta_id']) {
                        $revertOp = ($oldTx['tipo'] === 'RECEITA') ? '-' : '+';
                        $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual $revertOp ? WHERE id = ?")->execute([$oldTx['valor'], $oldTx['conta_id']]);
                    }
                    
                    $stmt = $pdo->prepare("UPDATE lancamentos SET descricao=?, valor=?, tipo=?, data_competencia=?, status=?, categoria_id=?, conta_id=?, cartao_id=?, veiculo_id=?, residencia_id=?, tipo_recorrencia=?, is_shared=?, meta_dados=? WHERE id=? AND familia_id=?");
                    $stmt->execute([$descricao, $valor, $tipo, $data_comp, $status, $categoria_id, $conta_id, $cartao_id, $veiculo_id, $residencia_id, $recorrencia, $is_shared, $meta_dados, $id, $LOGGED_IN_FAMILY_ID]);
                    
                    if ($status === 'PAGO' && $conta_id) {
                        $applyOp = ($tipo === 'RECEITA') ? '+' : '-';
                        $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual $applyOp ? WHERE id = ?")->execute([$valor, $conta_id]);
                    }
                }
                
                $lancamento_ids[] = $id;
                $pdo->prepare("DELETE FROM lancamento_rateios WHERE lancamento_id = ?")->execute([$id]);
            }

            // SALVAR RATEIOS
            if (count($rateio) > 0) {
                $stmtRateio = $pdo->prepare("INSERT INTO lancamento_rateios (lancamento_id, usuario_id, valor) VALUES (?, ?, ?)");
                foreach ($lancamento_ids as $lid) {
                    $fator = ($action === 'add_transaction' && $recorrencia === 'PARCELADO') ? (1 / $qtd_parcelas) : 1;
                    foreach ($rateio as $r) {
                        $uid = $r['usuario_id'];
                        $val = clean_money_input($r['valor']) * $fator;
                        if ($val > 0) {
                            $stmtRateio->execute([$lid, $uid, $val]);
                        }
                    }
                }
            }

            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); header('Location: index.php?page=transactions&error=1'); exit; }
        
        $dest = $redirect_page ? $redirect_page : 'transactions';
        header("Location: index.php?page=$dest&success=1"); exit;
    }

    // --- CORREÇÃO SALÁRIO (UPDATE PROFILE) ---
    if ($action === 'update_profile') {
        // ... (código existente mantido)
        $foto_perfil_path = $CURRENT_USER['foto_perfil'];
        if (isset($_FILES['foto_perfil']) && $_FILES['foto_perfil']['error'] == 0) {
            $target_dir = "uploads/profiles/";
            if (!is_dir($target_dir)) { mkdir($target_dir, 0755, true); }

            // Valida o tipo real do arquivo (não confia na extensão nem no mime do cliente)
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
            $realMime = mime_content_type($_FILES['foto_perfil']['tmp_name']);
            $maxSize = 5 * 1024 * 1024; // 5 MB

            if (isset($allowed[$realMime]) && $_FILES['foto_perfil']['size'] <= $maxSize) {
                // Nome aleatório: impede sobrescrita e path/extension controlado pelo usuário
                $ext = $allowed[$realMime];
                $filename = $LOGGED_IN_USER_ID . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $target_file = $target_dir . $filename;
                if (move_uploaded_file($_FILES["foto_perfil"]["tmp_name"], $target_file)) {
                    $foto_perfil_path = $target_file;
                }
            }
            // Se não passar na validação, mantém a foto anterior silenciosamente.
        }
        
        $is_restricted = isset($_POST['is_restricted']) ? 1 : 0; 
        $renda = clean_money_input($_POST['renda_estimada']); 
        $dia_recebimento = !empty($_POST['dia_recebimento_renda']) ? $_POST['dia_recebimento_renda'] : null; 
        $conta_recebimento = !empty($_POST['conta_recebimento_id']) ? $_POST['conta_recebimento_id'] : null;
        
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, renda_estimada = ?, dia_recebimento_renda = ?, conta_recebimento_id = ?, foto_perfil = ?, is_restricted = ? WHERE id = ?"); 
            $stmt->execute([$_POST['nome'], $renda, $dia_recebimento, $conta_recebimento, $foto_perfil_path, $is_restricted, $LOGGED_IN_USER_ID]);
            
            if ($renda > 0 && $dia_recebimento) {
                $stmtDel = $pdo->prepare("DELETE FROM lancamentos WHERE usuario_id = ? AND descricao = 'Salário Mensal (Automático)' AND status = 'PENDENTE' AND data_competencia >= CURDATE() AND tipo = 'RECEITA'"); 
                $stmtDel->execute([$LOGGED_IN_USER_ID]);
                
                $stmtCat = $pdo->prepare("SELECT id FROM categorias WHERE familia_id = ? AND (nome LIKE 'Salário%' OR nome LIKE 'Renda%') AND tipo = 'RECEITA' LIMIT 1"); 
                $stmtCat->execute([$LOGGED_IN_FAMILY_ID]); 
                $categoriaSalarioId = $stmtCat->fetchColumn(); 
                
                if (!$categoriaSalarioId) {
                    $stmtNewCat = $pdo->prepare("INSERT INTO categorias (familia_id, nome, tipo, grupo, icone) VALUES (?, 'Salário/Renda', 'RECEITA', 'Receitas', 'fa-sack-dollar')");
                    $stmtNewCat->execute([$LOGGED_IN_FAMILY_ID]);
                    $categoriaSalarioId = $pdo->lastInsertId();
                }
                
                $recorrencia_grupo_id = uniqid(); 
                $baseDate = new DateTime(); 
                $targetDay = (int)$dia_recebimento; 
                if ($targetDay == 101) $targetDay = 5; 
                if ($targetDay == 102) $targetDay = 31;
                
                for ($i = 0; $i < 24; $i++) { 
                    $date = clone $baseDate; 
                    $date->modify("first day of +$i months"); 
                    if ($dia_recebimento == 102) { $date->modify("last day of this month"); } 
                    else { $daysInMonth = (int)$date->format('t'); $dayToSet = min($targetDay, $daysInMonth); $date->setDate((int)$date->format('Y'), (int)$date->format('m'), $dayToSet); } 
                    $data_competencia = $date->format('Y-m-d'); 
                    
                    $stmtIns = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, tipo_recorrencia, recorrencia_grupo_id, is_shared) VALUES (?, ?, ?, 'Salário Mensal (Automático)', ?, 'RECEITA', ?, 'PENDENTE', ?, ?, 'MENSAL', ?, ?)"); 
                    $stmtIns->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $renda, $data_competencia, $categoriaSalarioId, $conta_recebimento, $recorrencia_grupo_id, ($is_restricted ? 0 : 1)]); 
                }
            }
            $pdo->commit(); header('Location: index.php?page=profile&success=1'); exit;
        } catch (Exception $e) { $pdo->rollBack(); header('Location: index.php?page=profile&error=1'); exit; }
    }

    // ... Resto das ações (change_password, save_budget, add_to_goal, etc) ...
    // ...
    if ($action === 'delete_transaction') {
        // ... (código existente mantido)
        $id = $_POST['transaction_id'];
        $sql = "SELECT * FROM lancamentos WHERE id = ? AND familia_id = ?";
        $params = [$id, $LOGGED_IN_FAMILY_ID];
        if (!$IS_SUPER_ADMIN) {
            $sql .= " AND usuario_id = ?";
            $params[] = $LOGGED_IN_USER_ID;
        }
        
        $stmtCheck = $pdo->prepare($sql);
        $stmtCheck->execute($params);
        $transaction = $stmtCheck->fetch();

        if ($transaction) {
            $pdo->beginTransaction();
            try {
                if ($transaction['status'] === 'PAGO' && $transaction['conta_id']) {
                    $op = ($transaction['tipo'] === 'RECEITA') ? '-' : '+'; // Inverte a operação
                    $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual $op ? WHERE id = ?")->execute([$transaction['valor'], $transaction['conta_id']]);
                }

                $stmtDel = $pdo->prepare("UPDATE lancamentos SET deleted_at = NOW() WHERE id = ?");
                $stmtDel->execute([$id]);
                
                $pdo->commit();
                header("Location: index.php?page=" . ($redirect_page ?: 'transactions') . "&success=1");
            } catch (Exception $e) {
                $pdo->rollBack();
                header("Location: index.php?page=" . ($redirect_page ?: 'transactions') . "&error=1");
            }
        } else {
            header("Location: index.php?page=" . ($redirect_page ?: 'transactions') . "&error=permission_denied");
        }
        exit;
    }
    if ($action === 'add_account' || $action === 'edit_account') { 
        $saldo = clean_money_input($_POST['saldo_atual']);
        if ($action === 'add_account') {
            // Conta nova: saldo informado é tanto o inicial quanto o atual.
            $stmt = $pdo->prepare("INSERT INTO contas_bancarias (familia_id, usuario_id, nome_banco, tipo, saldo_inicial, saldo_atual) VALUES (?, ?, ?, ?, ?, ?)"); 
            $stmt->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $_POST['nome_banco'], $_POST['tipo'], $saldo, $saldo]);
        } else {
            // Reajuste manual: o usuário informa o saldo atual correto. Recalcula o
            // saldo_inicial para que a reconciliação reproduza esse saldo (saldo_inicial
            // = saldo_informado - movimentos pagos da conta), mantendo a base íntegra.
            $accId = $_POST['id'];
            $stmtMov = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo='RECEITA' THEN valor ELSE -valor END),0) FROM lancamentos WHERE conta_id = ? AND status='PAGO' AND deleted_at IS NULL");
            $stmtMov->execute([$accId]);
            $mov = (float)$stmtMov->fetchColumn();
            $novoInicial = $saldo - $mov;
            $stmt = $pdo->prepare("UPDATE contas_bancarias SET nome_banco=?, tipo=?, saldo_inicial=?, saldo_atual=? WHERE id=? AND familia_id=?");
            $stmt->execute([$_POST['nome_banco'], $_POST['tipo'], $novoInicial, $saldo, $accId, $LOGGED_IN_FAMILY_ID]);
        }
        header('Location: index.php?page=assets&success=1'); exit; 
    }
    if ($action === 'add_card' || $action === 'edit_card') {
         $limite = clean_money_input($_POST['limite_total']);
         if ($action === 'add_card') {
            $stmt = $pdo->prepare("INSERT INTO cartoes_credito (familia_id, usuario_id, nome_cartao, limite_total, dia_vencimento, dia_fechamento) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $_POST['nome_cartao'], $limite, $_POST['dia_vencimento'], $_POST['dia_fechamento']]);
         } else {
            $stmt = $pdo->prepare("UPDATE cartoes_credito SET nome_cartao=?, limite_total=?, dia_vencimento=?, dia_fechamento=? WHERE id=? AND familia_id=?");
            $stmt->execute([$_POST['nome_cartao'], $limite, $_POST['dia_vencimento'], $_POST['dia_fechamento'], $_POST['id'], $LOGGED_IN_FAMILY_ID]);
         }
         header('Location: index.php?page=assets&success=1'); exit;
    }
    if ($action === 'add_vehicle' || $action === 'edit_vehicle') { 
        $placa = strtoupper($_POST['placa']); $renavam = $_POST['renavam']; $valor_estimado = clean_money_input($_POST['valor_estimado']);
        if ($action === 'add_vehicle') { 
            $stmt = $pdo->prepare("INSERT INTO ativos_veiculos (familia_id, descricao, marca, modelo, ano, valor_estimado, placa, renavam) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"); 
            $stmt->execute([$LOGGED_IN_FAMILY_ID, $_POST['descricao'], $_POST['marca'], $_POST['modelo'], $_POST['ano'], $valor_estimado, $placa, $renavam]); 
        } else { 
            $stmt = $pdo->prepare("UPDATE ativos_veiculos SET descricao=?, marca=?, modelo=?, ano=?, valor_estimado=?, placa=?, renavam=? WHERE id=? AND familia_id=?"); 
            $stmt->execute([$_POST['descricao'], $_POST['marca'], $_POST['modelo'], $_POST['ano'], $valor_estimado, $placa, $renavam, $_POST['id'], $LOGGED_IN_FAMILY_ID]); 
        } 
        header('Location: index.php?page=assets&success=1'); exit; 
    }
    if ($action === 'add_residence' || $action === 'edit_residence') {
        $valor_estimado = clean_money_input($_POST['valor_estimado']);
        if ($action === 'add_residence') {
            $stmt = $pdo->prepare("INSERT INTO ativos_residencias (familia_id, descricao, endereco, valor_estimado) VALUES (?, ?, ?, ?)");
            $stmt->execute([$LOGGED_IN_FAMILY_ID, $_POST['descricao'], $_POST['endereco'], $valor_estimado]);
        } else {
            $stmt = $pdo->prepare("UPDATE ativos_residencias SET descricao=?, endereco=?, valor_estimado=? WHERE id=? AND familia_id=?");
            $stmt->execute([$_POST['descricao'], $_POST['endereco'], $valor_estimado, $_POST['id'], $LOGGED_IN_FAMILY_ID]);
        }
        header('Location: index.php?page=assets&success=1'); exit;
    }
    if ($action === 'delete_asset') {
        $type = $_POST['asset_type']; $id = $_POST['asset_id'];
        $table = ''; if ($type === 'contas_bancarias') $table = 'contas_bancarias'; if ($type === 'cartoes_credito') $table = 'cartoes_credito'; if ($type === 'ativos_veiculos') $table = 'ativos_veiculos'; if ($type === 'ativos_residencias') $table = 'ativos_residencias';
        if ($table) { $stmt = $pdo->prepare("UPDATE $table SET deleted_at = NOW() WHERE id = ? AND familia_id = ?"); $stmt->execute([$id, $LOGGED_IN_FAMILY_ID]); }
        header('Location: index.php?page=assets&success=1'); exit;
    }
    if ($action === 'add_pet' || $action === 'edit_pet') {
        if ($action === 'add_pet') { $stmt = $pdo->prepare("INSERT INTO pets (familia_id, nome, raca, data_nascimento, peso) VALUES (?, ?, ?, ?, ?)"); $stmt->execute([$LOGGED_IN_FAMILY_ID, $_POST['nome'], $_POST['raca'], $_POST['data_nascimento'], $_POST['peso']]); } 
        else { $stmt = $pdo->prepare("UPDATE pets SET nome=?, raca=?, data_nascimento=?, peso=? WHERE id=? AND familia_id=?"); $stmt->execute([$_POST['nome'], $_POST['raca'], $_POST['data_nascimento'], $_POST['peso'], $_POST['pet_id'], $LOGGED_IN_FAMILY_ID]); }
        header('Location: index.php?page=pets&success=1'); exit;
    }
    if ($action === 'delete_pet') { $stmt = $pdo->prepare("UPDATE pets SET deleted_at = NOW() WHERE id = ? AND familia_id = ?"); $stmt->execute([$_POST['pet_id'], $LOGGED_IN_FAMILY_ID]); header('Location: index.php?page=pets&success=1'); exit; }
    if ($action === 'add_vaccine') {
        // IDOR: confirma que o pet pertence à família antes de registrar vacina/lançamento
        $stmtPet = $pdo->prepare("SELECT id FROM pets WHERE id = ? AND familia_id = ? AND deleted_at IS NULL");
        $stmtPet->execute([$_POST['pet_id'] ?? 0, $LOGGED_IN_FAMILY_ID]);
        if (!$stmtPet->fetchColumn()) {
            header('Location: index.php?page=pets&error=permission_denied'); exit;
        }
        $pdo->beginTransaction();
        try {
            $financial_recurrence = $_POST['recorrencia_financeira'] ?? 'UNICA'; $qtd_parcelas = $_POST['qtd_parcelas'] ?? 1; $custo = clean_money_input($_POST['custo'] ?? 0); $first_lancamento_id = null;
            if ($custo > 0) {
                if ($financial_recurrence === 'PARCELADO' && $qtd_parcelas > 1) {
                    $recorrencia_grupo_id = uniqid();
                    for ($i = 1; $i <= $qtd_parcelas; $i++) {
                        $data_parcela = date('Y-m-d', strtotime("+" . ($i - 1) . " months", strtotime($_POST['data_aplicacao'])));
                        $descricao_parcela = "Vacina: " . $_POST['nome_vacina'] . " ($i/$qtd_parcelas)";
                        $stmt = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, cartao_id, tipo_recorrencia, parcela_atual, parcela_total, recorrencia_grupo_id) VALUES (?, ?, ?, ?, ?, 'DESPESA', ?, 'PENDENTE', ?, ?, ?, 'UNICA', ?, ?, ?)");
                        $stmt->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $descricao_parcela, $custo / $qtd_parcelas, $data_parcela, $_POST['categoria_id'], !empty($_POST['conta_id']) ? $_POST['conta_id'] : null, !empty($_POST['cartao_id']) ? $_POST['cartao_id'] : null, $i, $qtd_parcelas, $recorrencia_grupo_id]);
                        if ($i === 1) $first_lancamento_id = $pdo->lastInsertId();
                    }
                } else {
                    $stmt = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, categoria_id, conta_id, cartao_id, tipo_recorrencia) VALUES (?, ?, ?, ?, ?, 'DESPESA', ?, 'PAGO', ?, ?, ?, ?)");
                    $stmt->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, 'Vacina: ' . $_POST['nome_vacina'], $custo, $_POST['data_aplicacao'], $_POST['categoria_id'], !empty($_POST['conta_id']) ? $_POST['conta_id'] : null, !empty($_POST['cartao_id']) ? $_POST['cartao_id'] : null, $financial_recurrence]);
                    $first_lancamento_id = $pdo->lastInsertId();
                    
                    if(!empty($_POST['conta_id']) && empty($_POST['cartao_id'])) {
                        $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual - ? WHERE id = ?")->execute([$custo, $_POST['conta_id']]);
                    }
                }
            }
            $proxima = null; $vaccine_recurrence = $_POST['recorrencia_vacina'] ?? 'UNICA';
            if ($vaccine_recurrence !== 'UNICA') { $map = ['ANUAL' => '+1 year', 'SEMESTRAL' => '+6 months', 'TRIMESTRAL' => '+3 months', 'MENSAL' => '+1 month']; $dataBase = $_POST['data_aplicacao']; $proxima = date('Y-m-d', strtotime($map[$vaccine_recurrence], strtotime($dataBase))); }
            $stmt = $pdo->prepare("INSERT INTO pet_vacinacoes (pet_id, nome_vacina, data_aplicacao, proxima_dose, custo, recorrencia, lancamento_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['pet_id'], $_POST['nome_vacina'], $_POST['data_aplicacao'], $proxima, $custo, $vaccine_recurrence, $first_lancamento_id]);
            $pdo->commit(); header('Location: index.php?page=pets&success=1'); exit;
        } catch (Exception $e) { $pdo->rollBack(); header('Location: index.php?page=pets&error=1'); exit; }
    }
    if ($action === 'delete_vaccine') {
        // IDOR: só exclui vacina vinculada a um pet da própria família
        $stmt = $pdo->prepare("UPDATE pet_vacinacoes pv JOIN pets p ON pv.pet_id = p.id SET pv.deleted_at = NOW() WHERE pv.id = ? AND p.familia_id = ?");
        $stmt->execute([$_POST['vaccine_id'], $LOGGED_IN_FAMILY_ID]);
        header('Location: index.php?page=pets&success=1'); exit;
    }
    if ($action === 'change_password') {
        $nova = $_POST['nova_senha'] ?? '';
        $confirma = $_POST['confirmar_nova_senha'] ?? '';
        // Valida senha atual, coincidência e força mínima (8 caracteres, igual ao registro/reset)
        if (password_verify($_POST['senha_atual'] ?? '', $CURRENT_USER['senha_hash'])
            && $nova === $confirma
            && strlen($nova) >= 8) {
            $hash = password_hash($nova, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
            $stmt->execute([$hash, $LOGGED_IN_USER_ID]);
            header('Location: index.php?page=profile&success_pass=1'); exit;
        }
        header('Location: index.php?page=profile&error_pass=1'); exit;
    }
    if ($action === 'save_budget') {
        $orcamentos = $_POST['orcamento'] ?? []; $ativos = $_POST['ativo'] ?? []; $escopos = $_POST['escopo'] ?? []; $mes = date('n'); $ano = date('Y');
        $pdo->beginTransaction();
        foreach ($orcamentos as $catId => $valor) { 
            $valor = clean_money_input($valor); 
            $ativo = (isset($ativos[$catId]) && $ativos[$catId] == '1') ? 1 : 0; 
            $escopo = $escopos[$catId] ?? 'FAMILIA'; 
            $stmt = $pdo->prepare("INSERT INTO orcamentos (familia_id, categoria_id, valor_orcado, mes, ano, ativo, escopo) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor_orcado = ?, ativo = ?, escopo = ?"); 
            $stmt->execute([$LOGGED_IN_FAMILY_ID, $catId, $valor, $mes, $ano, $ativo, $escopo, $valor, $ativo, $escopo]); 
        }
        $pdo->commit(); header('Location: index.php?page=budget&success=1'); exit;
    }
    if ($action === 'add_comment') {
        // IDOR: garante que o lançamento comentado pertence à família do usuário
        $stmtChk = $pdo->prepare("SELECT id FROM lancamentos WHERE id = ? AND familia_id = ? AND deleted_at IS NULL");
        $stmtChk->execute([$_POST['lancamento_id'], $LOGGED_IN_FAMILY_ID]);
        if (!$stmtChk->fetchColumn()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'permission_denied']); exit;
        }
        $stmt = $pdo->prepare("INSERT INTO lancamento_comentarios (lancamento_id, usuario_id, comentario) VALUES (?, ?, ?)");
        $stmt->execute([$_POST['lancamento_id'], $LOGGED_IN_USER_ID, $_POST['comentario']]);
        echo json_encode(['success' => true]); exit;
    }
    if ($action === 'update_family_settings') { $stmt = $pdo->prepare("UPDATE familias SET moeda = ?, idioma = ? WHERE id = ?"); $stmt->execute([$_POST['moeda'], $_POST['idioma'], $LOGGED_IN_FAMILY_ID]); header('Location: index.php?page=profile&success=1'); exit; }
    if ($action === 'reconcile_balances') {
        // Rede de segurança: recalcula o saldo das contas a partir dos lançamentos pagos.
        // Restrito a Guardião ou super admin (operação sensível sobre saldos).
        if ($CURRENT_USER['tipo_perfil'] !== 'GUARDIÃO' && !$IS_SUPER_ADMIN) {
            header('Location: index.php?page=assets&error=permission_denied'); exit;
        }
        $n = reconcile_account_balances($pdo, $LOGGED_IN_FAMILY_ID);
        header('Location: index.php?page=assets&success=1&msg=' . urlencode("Saldos reconciliados ($n contas)." )); exit;
    }
    if ($action === 'add_member') {
        $renda = clean_money_input($_POST['renda_estimada']);
        // Gera senha aleatória forte. O membro deve redefinir via "Esqueci a senha".
        $randomPass = bin2hex(random_bytes(12));
        $tempPass = password_hash($randomPass, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (familia_id, nome, email, senha_hash, tipo_perfil, renda_estimada, dia_recebimento_renda, ip_cadastro) VALUES (?, ?, ?, ?, 'JOVEM', ?, ?, ?)");
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $stmt->execute([$LOGGED_IN_FAMILY_ID, $_POST['nome'], $_POST['email'], $tempPass, $renda, $_POST['dia_recebimento_renda'], $ip]);
        header('Location: index.php?page=family&success=1&member_added=1'); exit;
    }
    if ($action === 'edit_member') { $renda = clean_money_input($_POST['renda_estimada']); $stmt = $pdo->prepare("UPDATE usuarios SET nome=?, renda_estimada=?, dia_recebimento_renda=?, tipo_perfil=? WHERE id=? AND familia_id=?"); $stmt->execute([$_POST['nome'], $renda, $_POST['dia_recebimento_renda'], $_POST['tipo_perfil'], $_POST['usuario_id'], $LOGGED_IN_FAMILY_ID]); header('Location: index.php?page=family&success=1'); exit; }
    if ($action === 'delete_member') { $stmt = $pdo->prepare("UPDATE usuarios SET deleted_at = NOW() WHERE id=? AND familia_id=?"); $stmt->execute([$_POST['usuario_id'], $LOGGED_IN_FAMILY_ID]); header('Location: index.php?page=family&success=1'); exit; }
    if ($action === 'add_goal' || $action === 'edit_goal') { $valor_alvo = clean_money_input($_POST['valor_alvo']); if ($action === 'add_goal') { $stmt = $pdo->prepare("INSERT INTO metas (familia_id, nome, valor_alvo, data_limite) VALUES (?, ?, ?, ?)"); $stmt->execute([$LOGGED_IN_FAMILY_ID, $_POST['nome'], $valor_alvo, $_POST['data_limite']]); } else { $stmt = $pdo->prepare("UPDATE metas SET nome=?, valor_alvo=?, data_limite=? WHERE id=? AND familia_id=?"); $stmt->execute([$_POST['nome'], $valor_alvo, $_POST['data_limite'], $_POST['meta_id'], $LOGGED_IN_FAMILY_ID]); } header('Location: index.php?page=goals&success=1'); exit; }
    if ($action === 'delete_goal') { $stmt = $pdo->prepare("UPDATE metas SET deleted_at=NOW() WHERE id=? AND familia_id=?"); $stmt->execute([$_POST['meta_id'], $LOGGED_IN_FAMILY_ID]); header('Location: index.php?page=goals&success=1'); exit; }
    if ($action === 'add_to_goal') {
        $valor = clean_money_input($_POST['valor']);
        $meta_id = $_POST['meta_id'];

        // IDOR: confirma que a meta é da família
        $stmtMeta = $pdo->prepare("SELECT id FROM metas WHERE id = ? AND familia_id = ? AND deleted_at IS NULL");
        $stmtMeta->execute([$meta_id, $LOGGED_IN_FAMILY_ID]);
        if (!$stmtMeta->fetchColumn()) {
            header('Location: index.php?page=goals&error=permission_denied'); exit;
        }

        // IDOR: se houver conta de origem, confirma que também é da família
        $conta_origem_id = null;
        if (!empty($_POST['conta_origem_id'])) {
            $stmtConta = $pdo->prepare("SELECT id FROM contas_bancarias WHERE id = ? AND familia_id = ? AND deleted_at IS NULL");
            $stmtConta->execute([$_POST['conta_origem_id'], $LOGGED_IN_FAMILY_ID]);
            if (!$stmtConta->fetchColumn()) {
                header('Location: index.php?page=goals&error=permission_denied'); exit;
            }
            $conta_origem_id = $_POST['conta_origem_id'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO meta_aportes (meta_id, usuario_id, valor, data_aporte) VALUES (?, ?, ?, ?)");
            $stmt->execute([$meta_id, $LOGGED_IN_USER_ID, $valor, $_POST['data_aporte']]);
            $stmtUpd = $pdo->prepare("UPDATE metas SET valor_atual = valor_atual + ? WHERE id = ?");
            $stmtUpd->execute([$valor, $meta_id]);
            if ($conta_origem_id) {
                $stmtL = $pdo->prepare("INSERT INTO lancamentos (familia_id, usuario_id, criador_id, descricao, valor, tipo, data_competencia, status, conta_id, tipo_recorrencia) VALUES (?, ?, ?, 'Aporte: Meta', ?, 'DESPESA', ?, 'PAGO', ?, 'UNICA')");
                $stmtL->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID, $LOGGED_IN_USER_ID, $valor, $_POST['data_aporte'], $conta_origem_id]);
                $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual - ? WHERE id = ?")->execute([$valor, $conta_origem_id]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header('Location: index.php?page=goals&error=1'); exit;
        }
        header('Location: index.php?page=goals&success=1'); exit;
    }
}

if(isset($_GET['action']) && $_GET['action'] === 'complete_onboarding') { header('Location: index.php?page=dashboard'); exit; }
$currentPage = $_GET['page'] ?? ($IS_SUPER_ADMIN ? 'superadmin' : 'dashboard');
if (isset($_SESSION['show_onboarding']) && $_SESSION['show_onboarding'] === true) { $currentPage = 'onboarding'; unset($_SESSION['show_onboarding']); }
include 'layout/header.php';
?>

<!-- Estrutura Principal -->
<div class="min-h-screen bg-slate-50">
    <?php if ($currentPage !== 'onboarding') include 'layout/sidebar.php'; ?>
    <div class="<?php echo $currentPage !== 'onboarding' ? 'lg:ml-64' : ''; ?> transition-all duration-300 flex flex-col min-h-screen">
        <?php if (isset($LOGGED_IN_USER_ID) && isset($CURRENT_USER) && $currentPage !== 'onboarding'): ?>
        <header class="sticky top-0 z-30 w-full bg-white/90 backdrop-blur border-b border-slate-200 px-4 py-3 sm:px-8 flex flex-wrap justify-between items-center gap-4">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden text-slate-500 hover:text-indigo-600 p-2 rounded-lg hover:bg-slate-100 transition-colors"><i class="fa-solid fa-bars text-xl"></i></button>
                <div><h1 class="text-xl font-bold text-slate-800 truncate max-w-[200px] sm:max-w-md"><?php echo htmlspecialchars($CURRENT_USER['nome_familia']); ?></h1><p class="text-xs text-slate-500 hidden sm:block"><?php echo _t('header_welcome'); ?>, <?php echo htmlspecialchars($CURRENT_USER['nome']); ?>!</p></div>
            </div>
            <div class="flex items-center gap-4">
                <div x-data="{ open: false }" class="relative">
                    <button @click="open = !open" class="relative text-slate-500 hover:text-indigo-600 transition-colors p-2"><i class="fa-solid fa-bell text-xl"></i><?php if ($unread_count > 0): ?><span class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white"></span><?php endif; ?></button>
                    <div x-show="open" @click.away="open = false" x-cloak class="absolute right-0 mt-2 w-80 bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden z-50">
                        <div class="p-3 font-bold text-sm border-b bg-slate-50 text-slate-700">Notificações</div>
                        <div class="divide-y max-h-64 overflow-y-auto">
                            <?php foreach($notifications as $notif): ?>
                            <a href="<?php echo htmlspecialchars($notif['link'] ?? '#'); ?>" class="block p-3 hover:bg-slate-50 transition-colors"><p class="font-bold text-xs text-slate-800"><?php echo htmlspecialchars($notif['titulo']); ?></p><p class="text-xs text-slate-500 mt-1"><?php echo htmlspecialchars($notif['mensagem']); ?></p></a>
                            <?php endforeach; ?>
                            <?php if (empty($notifications)): ?><div class="flex flex-col items-center justify-center p-6 text-slate-400"><i class="fa-regular fa-bell-slash text-2xl mb-2"></i><p class="text-xs">Nenhuma notificação nova.</p></div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="hidden sm:block"><?php if (!empty($_SESSION['is_admin_account'])): ?><a href="index.php?toggle_admin=1" class="text-xs font-bold px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors shadow-lg shadow-indigo-600/20"><?php echo $IS_SUPER_ADMIN ? 'Sair do Modo God' : 'Modo Super Admin'; ?></a><?php endif; ?></div>
            </div>
        </header>
        <?php endif; ?>
        <main class="flex-1 p-4 sm:p-8 overflow-y-auto">
            <?php $viewPath = "views/{$currentPage}.php"; if (file_exists($viewPath)) { include $viewPath; } else { include "views/placeholder.php"; } ?>
        </main>
        <?php include 'layout/footer.php'; ?>
    </div>
</div>