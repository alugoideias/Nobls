
<?php
header('Content-Type: application/json');
require_once 'db.php';

// 1. Busca a API Key
$stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'GEMINI_API_KEY'");
$stmt->execute();
$apiKey = $stmt->fetchColumn();

if (!$apiKey || $apiKey === 'SUA_API_KEY_AQUI') {
    echo json_encode(['error' => 'API Key não configurada.']);
    exit;
}

// 2. Busca Contexto (Categorias e Ativos) para a IA
$stmtCat = $pdo->prepare("SELECT id, nome, tipo FROM categorias WHERE familia_id = ?");
$stmtCat->execute([$LOGGED_IN_FAMILY_ID]);
$categorias = $stmtCat->fetchAll(PDO::FETCH_ASSOC);

$stmtVeh = $pdo->prepare("SELECT id, descricao FROM ativos_veiculos WHERE familia_id = ?");
$stmtVeh->execute([$LOGGED_IN_FAMILY_ID]);
$veiculos = $stmtVeh->fetchAll(PDO::FETCH_ASSOC);

$stmtRes = $pdo->prepare("SELECT id, descricao FROM ativos_residencias WHERE familia_id = ?");
$stmtRes->execute([$LOGGED_IN_FAMILY_ID]);
$residencias = $stmtRes->fetchAll(PDO::FETCH_ASSOC);

$contexto = [
    'categorias' => $categorias,
    'veiculos' => $veiculos,
    'residencias' => $residencias
];
$contextoJson = json_encode($contexto);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['documento'])) {
    echo json_encode(['error' => 'Requisição inválida.']);
    exit;
}

$file = $_FILES['documento'];
$fileMimeType = $file['type'];
$fileData = base64_encode(file_get_contents($file['tmp_name']));

// 3. Monta o Prompt Avançado
$prompt = "Você é um assistente contábil inteligente. Analise a imagem/PDF do comprovante financeiro e extraia os dados para preencher um formulário.
Use o seguinte contexto JSON dos dados do usuário para tentar mapear IDs existentes: $contextoJson

Regras:
1. 'descricao': Nome do estabelecimento ou descrição curta.
2. 'valor': Decimal com ponto (ex: 123.45).
3. 'data_vencimento': Formato AAAA-MM-DD.
4. 'categoria_id': Tente encontrar o ID da categoria mais apropriada na lista fornecida. Se não houver match óbvio, retorne null.
5. 'tipo': 'RECEITA' ou 'DESPESA'.
6. 'recorrencia': Se identificar que é uma assinatura (Netflix, Spotify, Aluguel), sugira 'MENSAL'. Se for IPVA/IPTU, sugira 'ANUAL'. Senão, 'UNICA'.
7. 'veiculo_id': Se a despesa for relacionada a carro (combustível, mecânica, IPVA) e houver veículos na lista, retorne o ID do veículo mais provável.
8. 'residencia_id': Se for relacionada a casa (luz, água, condomínio) e houver residências, retorne o ID.

Retorne APENAS um JSON válido. Exemplo:
{\"descricao\": \"Posto Shell\", \"valor\": 250.00, \"data_vencimento\": \"2023-10-28\", \"tipo\": \"DESPESA\", \"categoria_id\": 5, \"recorrencia\": \"UNICA\", \"veiculo_id\": 2, \"residencia_id\": null}";

$requestPayload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
                [
                    'inline_data' => [
                        'mime_type' => $fileMimeType,
                        'data' => $fileData
                    ]
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'response_mime_type' => 'application/json'
    ]
];

// Usando gemini-2.5-flash-latest
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-latest:generateContent?key=' . $apiKey;

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestPayload));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    echo json_encode(['error' => 'Erro na API Gemini.', 'details' => json_decode($response)]);
    exit;
}

$responseData = json_decode($response, true);
$textResponse = $responseData['candidates'][0]['content']['parts'][0]['text'] ?? null;

if (!$textResponse) {
    echo json_encode(['error' => 'Falha ao analisar documento.']);
    exit;
}

// Limpeza do JSON (embora response_mime_type deva garantir, é bom prevenir)
$jsonText = trim($textResponse, " \t\n\r\0\x0B`");
if (strpos($jsonText, 'json') === 0) {
    $jsonText = substr($jsonText, 4);
}
// Remove ``` se ainda existir
$jsonText = str_replace('```', '', $jsonText);

echo $jsonText;
?>
