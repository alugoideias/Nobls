<?php
header('Content-Type: application/json');
require_once 'db.php';

$action = $_GET['action'] ?? '';

// --- LÓGICA DE PRIVACIDADE GERAL ---
$privacyCondition = " AND (
    l.usuario_id = $LOGGED_IN_USER_ID 
    OR u.is_restricted = 0 
    OR l.is_shared = 1
    OR l.veiculo_id IS NOT NULL 
    OR l.residencia_id IS NOT NULL
)";

if ($action === 'get_transaction_splits') {
    $lancamento_id = $_GET['id'] ?? 0;
    
    // Verifica permissão
    $stmtCheck = $pdo->prepare("SELECT 1 FROM lancamentos l JOIN usuarios u ON l.usuario_id = u.id WHERE l.id = ? AND l.familia_id = ? $privacyCondition");
    $stmtCheck->execute([$lancamento_id, $LOGGED_IN_FAMILY_ID]);
    
    if (!$stmtCheck->fetch()) {
        echo json_encode([]); 
        exit;
    }

    $stmt = $pdo->prepare("SELECT usuario_id, valor FROM lancamento_rateios WHERE lancamento_id = ?");
    $stmt->execute([$lancamento_id]);
    $splits = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formata valores
    $result = [];
    foreach($splits as $s) {
        $result[] = [
            'usuario_id' => $s['usuario_id'],
            'valor' => (float)$s['valor'] // Retorna float para o JS manipular
        ];
    }
    
    echo json_encode($result);
    exit;
}

if ($action === 'get_comments') {
    $lancamento_id = $_GET['transaction_id'] ?? 0;
    $stmtCheck = $pdo->prepare("SELECT 1 FROM lancamentos l JOIN usuarios u ON l.usuario_id = u.id WHERE l.id = ? AND l.familia_id = ? $privacyCondition");
    $stmtCheck->execute([$lancamento_id, $LOGGED_IN_FAMILY_ID]);
    if (!$stmtCheck->fetch()) { echo json_encode([]); exit; }
    $stmt = $pdo->prepare("SELECT lc.*, u.nome as usuario_nome, u.foto_perfil FROM lancamento_comentarios lc JOIN usuarios u ON lc.usuario_id = u.id WHERE lc.lancamento_id = ? ORDER BY lc.created_at ASC");
    $stmt->execute([$lancamento_id]);
    $comments = $stmt->fetchAll();
    echo json_encode($comments);
    exit;
}

if ($action === 'get_dashboard_data') {
    $month = $_GET['month'] ?? date('n');
    $year = $_GET['year'] ?? date('Y');
    $user_id = $_GET['user_id'] ?? '';
    
    // Filtros de Lançamentos (Respeita privacidade estrita)
    $userSql = $user_id ? " AND l.usuario_id = ? " : $privacyCondition;
    $userParam = $user_id ? [$user_id] : [];
    
    $stmt = $pdo->prepare("SELECT SUM(valor) FROM lancamentos l JOIN usuarios u ON l.usuario_id = u.id WHERE l.familia_id = ? AND l.tipo = 'RECEITA' AND l.is_internal_transfer = 0 AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL $userSql");
    $stmt->execute(array_merge([$LOGGED_IN_FAMILY_ID, $month, $year], $userParam));
    $income = $stmt->fetchColumn() ?? 0;

    $stmt = $pdo->prepare("SELECT SUM(valor) FROM lancamentos l JOIN usuarios u ON l.usuario_id = u.id WHERE l.familia_id = ? AND l.tipo = 'DESPESA' AND l.is_internal_transfer = 0 AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL $userSql");
    $stmt->execute(array_merge([$LOGGED_IN_FAMILY_ID, $month, $year], $userParam));
    $expense = $stmt->fetchColumn() ?? 0;

    // --- CORREÇÃO SALDO LÍQUIDO (KPI) ---
    // Se for filtro específico, usa o ID. 
    // Se for visão geral (sem filtro):
    // - Se for Guardião: Vê saldo de TODOS (ignora is_restricted).
    // - Se for Membro Comum: Vê saldo próprio + membros públicos.
    if ($user_id) {
        $accPrivacy = " AND c.usuario_id = ? ";
        $accParam = [$user_id];
    } else {
        if ($CURRENT_USER['tipo_perfil'] === 'GUARDIÃO') {
            $accPrivacy = ""; // Guardião vê tudo no totalizador
            $accParam = [];
        } else {
            $accPrivacy = " AND (c.usuario_id = $LOGGED_IN_USER_ID OR u.is_restricted = 0) ";
            $accParam = [];
        }
    }

    $stmt = $pdo->prepare("SELECT SUM(c.saldo_atual) as total FROM contas_bancarias c JOIN usuarios u ON c.usuario_id = u.id WHERE c.familia_id = ? AND c.deleted_at IS NULL $accPrivacy");
    $stmt->execute(array_merge([$LOGGED_IN_FAMILY_ID], $accParam));
    $totalBalance = $stmt->fetchColumn() ?? 0;

    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(NULLIF(c.grupo, ''), c.nome) as label_name, 
            SUM(l.valor) as total 
        FROM lancamentos l 
        JOIN categorias c ON l.categoria_id = c.id 
        JOIN usuarios u ON l.usuario_id = u.id 
        WHERE l.familia_id = ? AND l.tipo = 'DESPESA' AND l.is_internal_transfer = 0 AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL $userSql 
        GROUP BY label_name 
        ORDER BY total DESC 
        LIMIT 8
    ");
    $stmt->execute(array_merge([$LOGGED_IN_FAMILY_ID, $month, $year], $userParam));
    $categorySpending = $stmt->fetchAll();
    
    $colors = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#3b82f6', '#14b8a6', '#f97316', '#84cc16'];

    $categoryChartData = [
        'labels' => array_column($categorySpending, 'label_name'), 
        'datasets' => [[
            'data' => array_column($categorySpending, 'total'), 
            'backgroundColor' => array_slice($colors, 0, count($categorySpending))
        ]]
    ];

    echo json_encode([
        'kpis' => [
            'totalBalance' => format_currency($totalBalance, $CURRENT_USER['moeda']),
            'income' => format_currency($income, $CURRENT_USER['moeda']),
            'expense' => format_currency($expense, $CURRENT_USER['moeda']),
            'balance' => format_currency($income - $expense, $CURRENT_USER['moeda']),
        ],
        'categoryChart' => $categoryChartData,
    ]);
    exit;
}

if ($action === 'get_calendar_events') {
    $month = $_GET['month'] ?? date('n');
    $year = $_GET['year'] ?? date('Y');
    $user_id = $_GET['user_id'] ?? '';
    $userSql = $user_id ? " AND l.usuario_id = ? " : $privacyCondition;
    $userParam = $user_id ? [$user_id] : [];

    $stmt = $pdo->prepare("
        SELECT DAY(l.data_competencia) as dia, l.descricao, l.valor, l.tipo, c.nome as cat_nome, c.grupo as cat_grupo 
        FROM lancamentos l 
        JOIN usuarios u ON l.usuario_id = u.id 
        LEFT JOIN categorias c ON l.categoria_id = c.id
        WHERE l.familia_id = ? 
        AND (
            (l.tipo = 'RECEITA' AND l.descricao LIKE '%Salário%')
            OR (l.tipo = 'DESPESA' AND l.status = 'PENDENTE')
            OR (c.grupo IN ('Pets', 'Veículos'))
        )
        AND l.is_internal_transfer = 0
        AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? 
        AND l.deleted_at IS NULL $userSql 
        ORDER BY l.data_competencia ASC
    ");
    $stmt->execute(array_merge([$LOGGED_IN_FAMILY_ID, $month, $year], $userParam));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $events = [];
    foreach ($rows as $row) {
        $d = $row['dia'];
        if (!isset($events[$d])) $events[$d] = [];
        $isSalary = (strpos($row['descricao'], 'Salário') !== false && $row['tipo'] === 'RECEITA');
        $color = $isSalary ? 'text-emerald-600' : 'text-slate-600';
        $prefix = $isSalary ? '+' : '-';
        
        $events[$d][] = [
            'descricao' => $row['descricao'],
            'valor' => $prefix . ' ' . format_currency($row['valor'], $CURRENT_USER['moeda']),
            'color' => $color
        ];
    }
    echo json_encode($events);
    exit;
}

if ($action === 'get_pending_settlements') {
    $stmtOwe = $pdo->prepare("SELECT l.id, l.settlement_id, l.valor, l.descricao, u_cred.nome as outro_usuario FROM lancamentos l JOIN lancamentos l_cred ON l.settlement_id = l_cred.settlement_id AND l_cred.id != l.id JOIN usuarios u_cred ON l_cred.usuario_id = u_cred.id WHERE l.usuario_id = ? AND l.familia_id = ? AND l.tipo = 'DESPESA' AND l.is_internal_transfer = 1 AND l.status = 'PENDENTE' AND l.deleted_at IS NULL");
    $stmtOwe->execute([$LOGGED_IN_USER_ID, $LOGGED_IN_FAMILY_ID]);
    $iOwe = $stmtOwe->fetchAll();
    $stmtReceive = $pdo->prepare("SELECT l.id, l.settlement_id, l.valor, l.descricao, u_deb.nome as outro_usuario FROM lancamentos l JOIN lancamentos l_deb ON l.settlement_id = l_deb.settlement_id AND l_deb.id != l.id JOIN usuarios u_deb ON l_deb.usuario_id = u_deb.id WHERE l.usuario_id = ? AND l.familia_id = ? AND l.tipo = 'RECEITA' AND l.is_internal_transfer = 1 AND l.status = 'PENDENTE' AND l.deleted_at IS NULL");
    $stmtReceive->execute([$LOGGED_IN_USER_ID, $LOGGED_IN_FAMILY_ID]);
    $toReceive = $stmtReceive->fetchAll();
    $output = ['i_owe' => array_map(function($i){ $i['valor_fmt'] = format_currency($i['valor']); return $i; }, $iOwe), 'to_receive' => array_map(function($i){ $i['valor_fmt'] = format_currency($i['valor']); return $i; }, $toReceive)];
    echo json_encode($output); exit;
}
if ($action === 'get_vaccines') { $pet_id = $_GET['pet_id'] ?? 0; $stmt = $pdo->prepare("SELECT * FROM pet_vacinacoes WHERE pet_id = ? AND deleted_at IS NULL ORDER BY data_aplicacao DESC"); $stmt->execute([$pet_id]); $vaccines = $stmt->fetchAll(); echo json_encode($vaccines); exit; }
if ($action === 'get_asset_history') { $type = $_GET['type'] ?? ''; $id = $_GET['id'] ?? 0; $column = ($type === 'vehicle') ? 'veiculo_id' : 'residencia_id'; $stmt = $pdo->prepare("SELECT l.*, c.nome as categoria_nome, u.nome as usuario_nome FROM lancamentos l LEFT JOIN categorias c ON l.categoria_id = c.id LEFT JOIN usuarios u ON l.usuario_id = u.id WHERE l.$column = ? AND l.familia_id = ? AND l.deleted_at IS NULL ORDER BY l.data_competencia DESC LIMIT 500"); $stmt->execute([$id, $LOGGED_IN_FAMILY_ID]); $history = $stmt->fetchAll(); foreach ($history as &$h) { $h['valor_fmt'] = format_currency($h['valor'], $CURRENT_USER['moeda']); $h['data_fmt'] = date('d/m/Y', strtotime($h['data_competencia'])); } echo json_encode($history); exit; }
if ($action === 'get_goal_history') { $meta_id = $_GET['meta_id'] ?? 0; $stmt = $pdo->prepare("SELECT ma.*, u.nome as usuario_nome, u.foto_perfil FROM meta_aportes ma JOIN usuarios u ON ma.usuario_id = u.id WHERE ma.meta_id = ? ORDER BY ma.data_aporte DESC"); $stmt->execute([$meta_id]); $aportes = $stmt->fetchAll(); foreach ($aportes as &$a) { $a['valor_fmt'] = format_currency($a['valor'], $CURRENT_USER['moeda']); $a['data_fmt'] = date('d/m/Y', strtotime($a['data_aporte'])); } echo json_encode($aportes); exit; }
?>