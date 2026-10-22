
<?php
header('Content-Type: application/json');
require_once 'db.php'; 

// 1. Busca a API Key do banco de dados
$stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'GEMINI_API_KEY'");
$stmt->execute();
$apiKey = $stmt->fetchColumn();

if (!$apiKey || $apiKey === 'SUA_API_KEY_AQUI') {
    echo json_encode(['error' => 'Chave da API Gemini não configurada no banco de dados.']);
    exit;
}

$reportType = $_GET['report'] ?? 'general';

// --- PRIVACIDADE: Define o que a Ise pode "ver" ---
$privacyCondition = " AND (
    l.usuario_id = $LOGGED_IN_USER_ID 
    OR u.is_restricted = 0 
    OR l.is_shared = 1
    OR l.veiculo_id IS NOT NULL 
    OR l.residencia_id IS NOT NULL
)";

// A. Resumo do Mês Atual
$currentMonth = date('n');
$currentYear = date('Y');
$stmtSummary = $pdo->prepare("
    SELECT 
        SUM(CASE WHEN tipo = 'RECEITA' THEN valor ELSE 0 END) as receitas,
        SUM(CASE WHEN tipo = 'DESPESA' THEN valor ELSE 0 END) as despesas
    FROM lancamentos l
    JOIN usuarios u ON l.usuario_id = u.id
    WHERE l.familia_id = ? AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL $privacyCondition
");
$stmtSummary->execute([$LOGGED_IN_FAMILY_ID, $currentMonth, $currentYear]);
$summary = $stmtSummary->fetch();

// B. Análise de Orçamento
$stmtBudget = $pdo->prepare("
    SELECT c.nome, o.valor_orcado, SUM(l.valor) as valor_gasto
    FROM orcamentos o
    JOIN categorias c ON o.categoria_id = c.id
    LEFT JOIN lancamentos l JOIN usuarios u ON l.usuario_id = u.id 
        ON l.categoria_id = c.id AND MONTH(l.data_competencia) = o.mes AND YEAR(l.data_competencia) = o.ano AND l.deleted_at IS NULL $privacyCondition
    WHERE o.familia_id = ? AND o.mes = ? AND o.ano = ?
    GROUP BY c.id
    HAVING valor_gasto > (valor_orcado * 0.9)
");
$stmtBudget->execute([$LOGGED_IN_FAMILY_ID, $currentMonth, $currentYear]);
$budgetAlerts = $stmtBudget->fetchAll();

// C. Top 5 Gastos do Mês
$stmtTop = $pdo->prepare("SELECT descricao, valor, data_competencia FROM lancamentos l JOIN usuarios u ON l.usuario_id = u.id WHERE l.familia_id = ? AND tipo = 'DESPESA' AND MONTH(data_competencia) = ? AND YEAR(data_competencia) = ? AND l.deleted_at IS NULL $privacyCondition ORDER BY valor DESC LIMIT 5");
$stmtTop->execute([$LOGGED_IN_FAMILY_ID, $currentMonth, $currentYear]);
$topExpenses = $stmtTop->fetchAll();

// D. Status das Metas
$stmtGoals = $pdo->prepare("SELECT nome, valor_alvo, valor_atual, data_limite FROM metas WHERE familia_id = ? AND deleted_at IS NULL");
$stmtGoals->execute([$LOGGED_IN_FAMILY_ID]);
$goals = $stmtGoals->fetchAll();

// E. Resumo Patrimonial (Contas Bancárias também têm dono)
$accPrivacy = " AND (c.usuario_id = $LOGGED_IN_USER_ID OR u.is_restricted = 0) ";
$stmtAssets = $pdo->prepare("SELECT 
    (SELECT SUM(c.saldo_atual) FROM contas_bancarias c JOIN usuarios u ON c.usuario_id = u.id WHERE c.familia_id = ? AND c.deleted_at IS NULL $accPrivacy) as bancos,
    (SELECT SUM(valor_estimado) FROM ativos_veiculos WHERE familia_id = ? AND deleted_at IS NULL) as veiculos,
    (SELECT SUM(valor_estimado) FROM ativos_residencias WHERE familia_id = ? AND deleted_at IS NULL) as imoveis
");
$stmtAssets->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_FAMILY_ID, $LOGGED_IN_FAMILY_ID]);
$assets = $stmtAssets->fetch();


// --- CONSTRUÇÃO DO PROMPT DA ISE ---

$contexto = [
    'mes_ref' => "$currentMonth/$currentYear",
    'resumo_financeiro' => $summary,
    'alertas_orcamento' => $budgetAlerts,
    'top_gastos' => $topExpenses,
    'metas' => $goals,
    'patrimonio' => $assets,
    'moeda' => $CURRENT_USER['moeda']
];

$prompt = "
Você é a **Ise**, uma assistente financeira de IA para uma família que usa o sistema Nobl$.
Seu tom é: Profissional, perspicaz, levemente otimista, mas firme quando vê problemas.
Seu objetivo: Analisar os dados JSON fornecidos e gerar um relatório HTML bonito e estruturado.

DADOS DA FAMÍLIA (JSON):
" . json_encode($contexto) . "

INSTRUÇÕES DE FORMATAÇÃO (HTML):
Não use Markdown (```html). Use apenas tags HTML puras.
Use classes do Tailwind CSS para estilização (text-colors, bg-colors, font-bold, p-4 rounded-xl, etc).
Estruture a resposta em 3 seções:

1. **Visão Geral da Ise**:
   - Um parágrafo resumindo a saúde financeira do mês.
   - Use um <div> com fundo claro (ex: bg-slate-50) e borda.
   - Se Gastos > Receitas, seja direta sobre o prejuízo.

2. **Pontos de Atenção (Se houver)**:
   - Liste orçamentos estourados ou gastos muito altos.
   - Use ícones (emojis ou font-awesome <i>) e cores de alerta (text-red-600).
   - Se estiver tudo bem, elogie.

3. **Plano de Ação e Insights**:
   - Sugira onde cortar gastos baseado no 'top_gastos'.
   - Comente sobre o progresso das metas.
   - Dê uma dica final motivacional.

IMPORTANTE:
- Fale diretamente com o usuário ('Você gastou...', 'Sua meta...').
- Formate os valores monetários corretamente ({$CURRENT_USER['moeda']}).
- Se os dados estiverem zerados (ex: sem lançamentos), dê uma mensagem de boas-vindas incentivando o uso.
";

$requestPayload = ['contents' => [['parts' => [['text' => $prompt]]]]];
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:generateContent?key=' . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    echo json_encode(['error' => 'Erro de conexão (CURL): ' . $curlError]);
    exit;
}

if ($httpCode !== 200) {
    $errorDetails = json_decode($response, true);
    $msg = $errorDetails['error']['message'] ?? 'Erro desconhecido da API Google.';
    echo json_encode(['error' => "Erro da Ise ($httpCode): $msg"]);
    exit;
}

$responseData = json_decode($response, true);
$textResponse = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

if ($textResponse) {
    $cleanHtml = preg_replace('/```html\s*|\s*```/', '', $textResponse);
    echo json_encode(['analysis' => $cleanHtml]);
} else {
    echo json_encode(['error' => 'A Ise recebeu os dados mas não gerou resposta.', 'raw' => $responseData]);
}
?>
