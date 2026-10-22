
<?php
session_start();
require_once 'db.php';

// Função auxiliar para redirecionar com erro detalhado
function redirectError($key, $msg = '') {
    $url = "index.php?page=import&error=$key";
    if ($msg) $url .= "&msg=" . urlencode($msg);
    header("Location: $url");
    exit;
}

// 1. Verificações de Upload
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectError('invalid_request', 'Método inválido.');
}

if (!isset($_FILES['extrato']) || $_FILES['extrato']['error'] !== UPLOAD_ERR_OK) {
    redirectError('upload_failed', 'Falha no upload do arquivo (Erro Code: ' . $_FILES['extrato']['error'] . ')');
}

$fileType = mime_content_type($_FILES['extrato']['tmp_name']);
if ($fileType !== 'application/pdf') {
    redirectError('invalid_format', 'O arquivo deve ser um PDF.');
}

// 2. Contexto de Destino
$target = $_POST['target_id'] ?? '';
$conta_id = null;
$cartao_id = null;

if (strpos($target, 'account_') === 0) {
    $conta_id = substr($target, 8);
} elseif (strpos($target, 'card_') === 0) {
    $cartao_id = substr($target, 5);
}

// 3. API Key e Contexto
$stmtKey = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'GEMINI_API_KEY'");
$stmtKey->execute();
$apiKey = $stmtKey->fetchColumn();

if (!$apiKey || $apiKey === 'SUA_API_KEY_AQUI') {
    redirectError('no_api_key', 'Chave da API Gemini não configurada.');
}

// Busca categorias para ajudar a IA
$stmtCat = $pdo->prepare("SELECT id, nome FROM categorias WHERE familia_id = ?");
$stmtCat->execute([$LOGGED_IN_FAMILY_ID]);
$categories = $stmtCat->fetchAll(PDO::FETCH_ASSOC);
$categoriesContext = json_encode($categories);

// 4. Preparar Payload e Prompt Otimizado para Faturas
$base64File = base64_encode(file_get_contents($_FILES['extrato']['tmp_name']));
$currentYear = date('Y');

$prompt = "
ATUE COMO UM CONTADOR EXPERIENTE. Analise este PDF (Fatura de Cartão ou Extrato).
Extraia as transações financeiras (compras, pagamentos, estornos).

CONTEXTO:
- Estamos no ano de $currentYear.
- Se a fatura for do Nubank, verifique a data de vencimento no cabeçalho para inferir o ano das transações (Ex: Vencimento JAN 2026, transações NOV são de 2025).
- Tente categorizar usando estes IDs: $categoriesContext.

REGRAS RÍGIDAS:
1. IGNORE linhas de resumo como 'Pagamento de fatura', 'Saldo anterior', 'Total a pagar', 'Pagamento recebido'.
2. EXTRAIA apenas transações de consumo ou serviços.
3. DATA: Formato YYYY-MM-DD. Se a data no PDF for apenas '24 NOV', calcule o ano correto baseado no vencimento da fatura.
4. PARCELAS: Se houver '12x', '01/10', 'Parcela 2 de 5', extraia 'parcela_atual' e 'parcela_total'.
5. VALOR: Sempre numérico positivo (float).

RETORNE APENAS JSON (Array de Objetos):
[
  {
    \"data\": \"2025-11-24\",
    \"descricao\": \"Uber *Trip\",
    \"valor\": 15.90,
    \"tipo\": \"DESPESA\",
    \"categoria_id\": 12,
    \"parcela_atual\": 1,
    \"parcela_total\": 1
  }
]
";

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => 'application/pdf',
                        'data' => $base64File
                    ]
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'response_mime_type' => 'application/json'
    ]
];

// 5. Chamada CURL Robusta
// Usando gemini-2.5-flash-latest para melhor compatibilidade multimodal
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-latest:generateContent?key=' . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
// IMPORTANTE: Desabilitar verificação SSL para evitar erros em localhost ou servidores sem certificados atualizados
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); 
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($curlError) {
    redirectError('connection_error', 'Erro de conexão: ' . $curlError);
}

if ($httpCode !== 200) {
    $errBody = json_decode($response, true);
    $errMsg = $errBody['error']['message'] ?? 'Erro desconhecido da API';
    redirectError('ai_api_error', "Erro da IA ($httpCode): $errMsg");
}

// 6. Processar JSON
$jsonResponse = json_decode($response, true);
$rawText = $jsonResponse['candidates'][0]['content']['parts'][0]['text'] ?? '';

// Com response_mime_type=json, o texto já deve ser JSON puro, mas removemos markdown por segurança
$cleanJson = preg_replace('/```json\s*|\s*```/', '', $rawText);
$transactions = json_decode($cleanJson, true);

if (!is_array($transactions)) {
    redirectError('ai_parse_error', 'A IA não retornou um JSON válido. Tente novamente.');
}

if (empty($transactions)) {
    redirectError('no_data', 'Nenhuma transação encontrada no documento.');
}

// 7. Sucesso - Salvar na Sessão
$_SESSION['import_staging'] = [
    'target_conta_id' => $conta_id,
    'target_cartao_id' => $cartao_id,
    'transactions' => $transactions
];

header('Location: index.php?page=import_preview');
exit;
