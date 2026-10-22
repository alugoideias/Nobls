
<?php
// --- REGRAS DE PRIVACIDADE ---
// Replica a lógica do Dashboard para que os relatórios sejam consistentes
$privacyCondition = " AND (
    l.usuario_id = $LOGGED_IN_USER_ID 
    OR u.is_restricted = 0 
    OR l.is_shared = 1
    OR l.veiculo_id IS NOT NULL 
    OR l.residencia_id IS NOT NULL
)";

// --- COLETA DE DADOS PARA GRÁFICOS ---

$cashflowData = [];
$catLabels = [];
$catValues = [];

// 1. Fluxo de Caixa e Economia (Últimos 6 meses)
for ($i = 5; $i >= 0; $i--) {
    $date = new DateTime("first day of -$i months");
    $month = $date->format('n');
    $year = $date->format('Y');
    
    // Receitas
    $stmt = $pdo->prepare("SELECT SUM(l.valor) FROM lancamentos l JOIN usuarios u ON l.usuario_id = u.id WHERE l.familia_id = ? AND l.tipo = 'RECEITA' AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL $privacyCondition");
    $stmt->execute([$LOGGED_IN_FAMILY_ID, $month, $year]);
    $income = $stmt->fetchColumn() ?? 0;

    // Despesas
    $stmt = $pdo->prepare("SELECT SUM(l.valor) FROM lancamentos l JOIN usuarios u ON l.usuario_id = u.id WHERE l.familia_id = ? AND l.tipo = 'DESPESA' AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL $privacyCondition");
    $stmt->execute([$LOGGED_IN_FAMILY_ID, $month, $year]);
    $expense = $stmt->fetchColumn() ?? 0;

    $cashflowData['labels'][] = $date->format('M/y');
    $cashflowData['income'][] = $income;
    $cashflowData['expense'][] = $expense;
    $cashflowData['savings'][] = $income - $expense; // Economia Mensal
}

// 2. Gastos por Categoria (Mês Atual)
$currM = date('n');
$currY = date('Y');
$stmtCat = $pdo->prepare("
    SELECT c.nome, SUM(l.valor) as total 
    FROM lancamentos l 
    JOIN categorias c ON l.categoria_id = c.id 
    JOIN usuarios u ON l.usuario_id = u.id
    WHERE l.familia_id = ? AND l.tipo = 'DESPESA' AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL $privacyCondition
    GROUP BY c.id 
    ORDER BY total DESC
");
$stmtCat->execute([$LOGGED_IN_FAMILY_ID, $currM, $currY]);
$allCats = $stmtCat->fetchAll();

$catColors = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#ef4444', '#8b5cf6', '#64748b'];
$finalCatData = [];
$otherTotal = 0;

foreach ($allCats as $index => $cat) {
    if ($index < 5) {
        $catLabels[] = $cat['nome'];
        $catValues[] = $cat['total'];
    } else {
        $otherTotal += $cat['total'];
    }
}
if ($otherTotal > 0) {
    $catLabels[] = 'Outros';
    $catValues[] = $otherTotal;
}

// Configuração Chart.js
$barChartData = [
    'labels' => $cashflowData['labels'],
    'datasets' => [
        ['label' => 'Receitas', 'data' => $cashflowData['income'], 'backgroundColor' => '#10b981', 'borderRadius' => 4],
        ['label' => 'Despesas', 'data' => $cashflowData['expense'], 'backgroundColor' => '#ef4444', 'borderRadius' => 4]
    ]
];

$lineChartData = [
    'labels' => $cashflowData['labels'],
    'datasets' => [
        [
            'label' => 'Economia Mensal (Saldo)', 
            'data' => $cashflowData['savings'], 
            'borderColor' => '#6366f1', 
            'backgroundColor' => 'rgba(99, 102, 241, 0.1)', 
            'borderWidth' => 2,
            'fill' => true,
            'tension' => 0.4
        ]
    ]
];

$doughnutChartData = [
    'labels' => $catLabels,
    'datasets' => [[
        'data' => $catValues,
        'backgroundColor' => $catColors,
        'borderWidth' => 0
    ]]
];
?>

<div x-data="reportsPage()" class="space-y-8 animate-fade-in">
    
    <!-- Hero da Ise -->
    <div class="bg-gradient-to-r from-indigo-900 to-slate-900 rounded-3xl p-8 text-white relative overflow-hidden shadow-2xl">
        <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse"></div>
        <div class="absolute bottom-0 left-0 w-64 h-64 bg-emerald-500 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-pulse" style="animation-delay: 2s"></div>

        <div class="relative z-10 flex flex-col md:flex-row items-center gap-8">
            <div class="relative group shrink-0">
                <div class="w-28 h-28 rounded-full border-4 border-white/20 shadow-2xl overflow-hidden bg-indigo-950/50 backdrop-blur flex items-center justify-center">
                    <img src="https://nobles.com.br/nobls/img/ise.png" alt="Ise Avatar" class="w-full h-full object-cover transform hover:scale-110 transition-transform duration-500">
                </div>
                <div class="absolute bottom-1 right-1 w-6 h-6 bg-emerald-500 border-4 border-slate-900 rounded-full animate-pulse" title="Ise Online"></div>
            </div>
            
            <div class="text-center md:text-left flex-1">
                <h1 class="text-3xl font-black tracking-tight mb-2">Olá, sou a Ise.</h1>
                <p class="text-indigo-100 text-lg max-w-xl">Sua inteligência financeira pessoal. Estou pronta para analisar seus dados e gerar insights sobre a dinastia <?php echo htmlspecialchars($CURRENT_USER['nome_familia']); ?>.</p>
                
                <div class="mt-6 flex flex-wrap gap-3 justify-center md:justify-start">
                    <button @click="analyze('general')" :disabled="loading" class="px-6 py-3 bg-white text-indigo-900 rounded-xl font-bold text-sm hover:bg-indigo-50 transition-all active:scale-95 shadow-lg disabled:opacity-70 disabled:cursor-not-allowed flex items-center gap-2">
                        <span x-show="!loading"><i class="fa-solid fa-wand-magic-sparkles"></i> GERAR INSIGHTS AGORA</span>
                        <span x-show="loading"><i class="fa-solid fa-circle-notch fa-spin"></i> CONECTANDO À ISE...</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Área de Resultado da Análise -->
    <div x-show="analysisResult || loading" x-transition class="bg-white rounded-3xl border border-indigo-100 shadow-xl overflow-hidden">
        <div class="p-1 bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500"></div>
        <div class="p-8">
            <div x-show="loading" class="text-center py-12">
                <div class="inline-block animate-bounce mb-4 text-4xl">✨</div>
                <h3 class="text-xl font-bold text-slate-800 mb-2">A Ise está pensando...</h3>
                <p class="text-slate-500 text-sm" x-text="loadingText"></p>
                <div class="w-64 h-2 bg-slate-100 rounded-full mx-auto mt-6 overflow-hidden">
                    <div class="h-full bg-indigo-600 rounded-full animate-[loading_2s_ease-in-out_infinite]" style="width: 50%"></div>
                </div>
            </div>

            <div x-show="!loading && analysisResult" class="prose max-w-none">
                <div class="flex items-center gap-3 mb-6 pb-6 border-b border-slate-100">
                    <div class="w-10 h-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600"><i class="fa-solid fa-comment-dots"></i></div>
                    <div>
                        <h3 class="font-bold text-slate-800 text-lg m-0">Relatório da Ise</h3>
                        <p class="text-xs text-slate-400 m-0">Gerado agora</p>
                    </div>
                </div>
                <!-- Conteúdo HTML injetado pela IA -->
                <div x-html="analysisResult" class="space-y-4 text-slate-600 leading-relaxed"></div>
            </div>
        </div>
    </div>

    <!-- Dashboard Analítico -->
    <div class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-6">
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm xl:col-span-2">
            <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2"><i class="fa-solid fa-chart-column text-slate-400"></i> Entradas vs Saídas (6 Meses)</h3>
            <div class="h-[250px]"><canvas id="cashflowChart"></canvas></div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm">
            <h3 class="font-bold text-slate-800 mb-6 flex items-center gap-2"><i class="fa-solid fa-chart-pie text-slate-400"></i> Onde gastou este mês?</h3>
            <div class="h-[250px] flex items-center justify-center">
                <?php if(empty($catValues)): ?><p class="text-slate-400 text-sm">Sem dados de despesas este mês.</p><?php else: ?><canvas id="doughnutChart"></canvas><?php endif; ?>
            </div>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200 p-6 shadow-sm xl:col-span-3">
             <h3 class="font-bold text-slate-800 mb-2 flex items-center gap-2"><i class="fa-solid fa-arrow-trend-up text-slate-400"></i> Tendência de Economia Líquida</h3>
             <p class="text-xs text-slate-500 mb-6">Resultado de Receitas menos Despesas ao longo do tempo.</p>
             <div class="h-[200px]"><canvas id="savingsChart"></canvas></div>
        </div>
    </div>
</div>

<style>@keyframes loading { 0% { transform: translateX(-100%); } 100% { transform: translateX(200%); } }</style>

<script>
function reportsPage() {
    return {
        loading: false,
        loadingText: 'Conectando aos dados da dinastia...',
        analysisResult: '',
        analyze(type) {
            this.loading = true;
            this.analysisResult = '';
            const steps = ['Analisando fluxo de caixa e orçamento...', 'Verificando desempenho dos investimentos...', 'Calculando saúde das metas...', 'Formulando estratégia personalizada...'];
            let stepIndex = 0;
            const interval = setInterval(() => { if(stepIndex < steps.length) { this.loadingText = steps[stepIndex++]; } }, 800);

            fetch('gemini_analyzer.php?report=' + type)
                .then(res => res.json())
                .then(data => {
                    clearInterval(interval);
                    if(data.error) {
                        this.analysisResult = `<div class="bg-red-50 text-red-600 p-4 rounded-xl border border-red-100 flex flex-col gap-2"><div class="flex items-center gap-2 font-bold"><i class="fa-solid fa-circle-exclamation text-xl"></i> Ops, a Ise teve um problema.</div><div class="text-sm opacity-80">${data.error}</div></div>`;
                    } else {
                        this.analysisResult = data.analysis;
                    }
                })
                .catch(err => { clearInterval(interval); this.analysisResult = `<div class="bg-red-50 text-red-600 p-4 rounded-xl">Erro: ${err.message}</div>`; })
                .finally(() => this.loading = false);
        },
        init() {
            new Chart(document.getElementById('cashflowChart').getContext('2d'), { type: 'bar', data: <?php echo json_encode($barChartData); ?>, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }, plugins: { legend: { position: 'bottom' } } } });
            const ctxDoughnut = document.getElementById('doughnutChart');
            if(ctxDoughnut) { new Chart(ctxDoughnut, { type: 'doughnut', data: <?php echo json_encode($doughnutChartData); ?>, options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { boxWidth: 10 } } } } }); }
            new Chart(document.getElementById('savingsChart').getContext('2d'), { type: 'line', data: <?php echo json_encode($lineChartData); ?>, options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: false, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }, plugins: { legend: { display: false } } } });
        }
    }
}
document.addEventListener('alpine:init', () => { Alpine.data('reportsPage', reportsPage); });
</script>
