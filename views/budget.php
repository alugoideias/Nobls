
<?php
// --- REGRAS DE PRIVACIDADE (helper centralizado; usa placeholder ?) ---
$privacyCondition = privacy_condition_lancamentos('l', 'u');

// --- NAVEGAÇÃO DE MÊS ---
$mes = $_GET['month'] ?? date('n');
$ano = $_GET['year'] ?? date('Y');

// Validação simples
$mes = (int)$mes;
$ano = (int)$ano;
if ($mes < 1 || $mes > 12) $mes = (int)date('n');
if ($ano < 2000 || $ano > 2100) $ano = (int)date('Y');

// Links Prox/Ant
$currentDateObj = new DateTime("$ano-$mes-01");
$prevDate = clone $currentDateObj; $prevDate->modify('-1 month');
$nextDate = clone $currentDateObj; $nextDate->modify('+1 month');

$prevLink = "?page=budget&month=" . $prevDate->format('n') . "&year=" . $prevDate->format('Y');
$nextLink = "?page=budget&month=" . $nextDate->format('n') . "&year=" . $nextDate->format('Y');

$meses = [1=>'Janeiro', 2=>'Fevereiro', 3=>'Março', 4=>'Abril', 5=>'Maio', 6=>'Junho', 7=>'Julho', 8=>'Agosto', 9=>'Setembro', 10=>'Outubro', 11=>'Novembro', 12=>'Dezembro'];
$mesNome = $meses[$mes];

// 1. Buscar categorias
$stmtCats = $pdo->prepare("SELECT id, nome, grupo, icone FROM categorias WHERE familia_id = ? AND tipo = 'DESPESA' AND deleted_at IS NULL ORDER BY grupo, nome");
$stmtCats->execute([$LOGGED_IN_FAMILY_ID]);
$expenseCategories = $stmtCats->fetchAll();

// 2. Buscar orçamentos do mês selecionado (Incluindo colunas ativo e escopo)
$stmtBudgets = $pdo->prepare("SELECT * FROM orcamentos WHERE familia_id = ? AND mes = ? AND ano = ?");
$stmtBudgets->execute([$LOGGED_IN_FAMILY_ID, $mes, $ano]);
$rawBudgets = $stmtBudgets->fetchAll();
$budgets = [];
foreach($rawBudgets as $rb) {
    $budgets[$rb['categoria_id']] = $rb;
}

// 3. Buscar gastos do mês selecionado
$stmtSpending = $pdo->prepare("
    SELECT l.categoria_id, SUM(l.valor) as total 
    FROM lancamentos l 
    JOIN usuarios u ON l.usuario_id = u.id
    WHERE l.familia_id = ? 
    AND l.tipo = 'DESPESA' 
    AND MONTH(l.data_competencia) = ? 
    AND YEAR(l.data_competencia) = ? 
    AND l.deleted_at IS NULL 
    $privacyCondition
    GROUP BY l.categoria_id
");
$stmtSpending->execute([$LOGGED_IN_FAMILY_ID, $mes, $ano, $LOGGED_IN_USER_ID]);
$spending = $stmtSpending->fetchAll(PDO::FETCH_KEY_PAIR);

// Totais
$totalOrcado = 0;
foreach($budgets as $b) {
    if(isset($b['ativo']) && $b['ativo'] == 0) continue; // Ignora inativos no total
    $totalOrcado += $b['valor_orcado'];
}

$totalGasto = array_sum($spending);
$percentGlobal = $totalOrcado > 0 ? ($totalGasto / $totalOrcado) * 100 : 0;
$disponivel = $totalOrcado - $totalGasto;

// Preparação dos dados para exibição e filtragem nas abas
$categoryData = [];
$countCritical = 0;

foreach ($expenseCategories as $category) {
    $budgetRow = $budgets[$category['id']] ?? [];
    $budgetValue = $budgetRow['valor_orcado'] ?? 0;
    $isActive = isset($budgetRow['ativo']) ? (bool)$budgetRow['ativo'] : true; // Padrão Ativo
    $scope = $budgetRow['escopo'] ?? 'FAMILIA'; // Padrão Familia

    $spentValue = $spending[$category['id']] ?? 0;
    $progress = $budgetValue > 0 ? ($spentValue / $budgetValue) * 100 : ($spentValue > 0 ? 100 : 0);
    
    // Define status apenas se estiver ativo
    $status = 'ok';
    if ($isActive) {
        if ($progress >= 100) $status = 'critical';
        elseif ($progress > 85) $status = 'warning';
        if ($status !== 'ok') $countCritical++;
    } else {
        $status = 'ignored';
    }

    // Cores
    $barColor = 'bg-indigo-500';
    $textColor = 'text-indigo-600';
    $iconBg = 'bg-indigo-100 text-indigo-600';
    
    if ($status === 'warning') { $barColor = 'bg-amber-500'; $textColor = 'text-amber-600'; $iconBg = 'bg-amber-100 text-amber-600'; }
    if ($status === 'critical') { $barColor = 'bg-rose-500'; $textColor = 'text-rose-600'; $iconBg = 'bg-rose-100 text-rose-600'; }
    if (!$isActive) { $barColor = 'bg-slate-300'; $textColor = 'text-slate-400'; $iconBg = 'bg-slate-100 text-slate-400'; }

    $categoryData[] = [
        'id' => $category['id'],
        'nome' => $category['nome'],
        'grupo' => $category['grupo'] ?? 'Geral',
        'icone' => $category['icone'] ?? 'fa-tag',
        'orcado' => $budgetValue,
        'gasto' => $spentValue,
        'progress' => $progress,
        'status' => $status,
        'ativo' => $isActive,
        'escopo' => $scope,
        'barColor' => $barColor,
        'textColor' => $textColor,
        'iconBg' => $iconBg
    ];
}

// Ordenar para mostrar críticos primeiro na aba "Todos"
usort($categoryData, function($a, $b) {
    // Inativos por último
    if (!$a['ativo'] && $b['ativo']) return 1;
    if ($a['ativo'] && !$b['ativo']) return -1;
    return $b['progress'] <=> $a['progress'];
});
?>
<div class="space-y-8 animate-fade-in" x-data="{ activeTab: '<?php echo $countCritical > 0 ? 'atencao' : 'todos'; ?>' }">
    
    <!-- Header e Navegação -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-4 border-b border-slate-200 pb-4">
        <div class="flex items-center gap-4 bg-white p-1.5 rounded-xl border border-slate-200 shadow-sm">
            <a href="<?php echo $prevLink; ?>" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-100 text-slate-400 transition-colors"><i class="fa-solid fa-chevron-left"></i></a>
            <div class="text-center px-2">
                <h2 class="text-sm font-bold text-slate-800 uppercase tracking-wide"><?php echo $mesNome; ?> <?php echo $ano; ?></h2>
            </div>
            <a href="<?php echo $nextLink; ?>" class="w-8 h-8 flex items-center justify-center rounded-lg hover:bg-slate-100 text-slate-400 transition-colors"><i class="fa-solid fa-chevron-right"></i></a>
        </div>
        
        <button type="submit" form="budget-form" class="w-full md:w-auto px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 hover:bg-indigo-700 transition-all active:scale-95 flex items-center justify-center gap-2">
            <i class="fa-solid fa-floppy-disk"></i> SALVAR METAS
        </button>
    </div>

    <!-- Cards de Resumo -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group hover:shadow-md transition-all">
            <div class="absolute right-0 top-0 w-24 h-24 bg-indigo-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
            <p class="text-slate-500 text-xs font-bold uppercase tracking-wider relative z-10">Planejado (Ativos)</p>
            <h3 class="text-3xl font-black text-slate-800 mt-2 relative z-10"><?php echo format_currency($totalOrcado, $CURRENT_USER['moeda']); ?></h3>
        </div>
        
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group hover:shadow-md transition-all">
            <div class="absolute right-0 top-0 w-24 h-24 bg-rose-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
            <p class="text-slate-500 text-xs font-bold uppercase tracking-wider relative z-10">Realizado</p>
            <h3 class="text-3xl font-black text-rose-600 mt-2 relative z-10"><?php echo format_currency($totalGasto, $CURRENT_USER['moeda']); ?></h3>
             <div class="w-full bg-slate-100 rounded-full h-1.5 mt-4 relative z-10">
                <div class="bg-rose-500 h-1.5 rounded-full shadow-[0_0_10px_rgba(244,63,94,0.5)]" style="width: <?php echo min($percentGlobal, 100); ?>%"></div>
            </div>
             <p class="text-[10px] text-slate-400 mt-1 relative z-10 font-bold"><?php echo round($percentGlobal); ?>% consumido</p>
        </div>
        
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden group hover:shadow-md transition-all">
            <div class="absolute right-0 top-0 w-24 h-24 bg-emerald-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
            <p class="text-slate-500 text-xs font-bold uppercase tracking-wider relative z-10">Disponível</p>
            <h3 class="text-3xl font-black <?php echo $disponivel >= 0 ? 'text-emerald-600' : 'text-red-600'; ?> mt-2 relative z-10"><?php echo format_currency($disponivel, $CURRENT_USER['moeda']); ?></h3>
        </div>
    </div>

    <!-- Navegação por Abas -->
    <div class="flex gap-2 border-b border-slate-200 overflow-x-auto">
        <button @click="activeTab = 'atencao'" 
                class="px-6 py-3 border-b-2 font-bold text-sm transition-all flex items-center gap-2 whitespace-nowrap"
                :class="activeTab === 'atencao' ? 'text-rose-600 border-rose-600 bg-rose-50 rounded-t-lg' : 'text-slate-500 border-transparent hover:text-slate-700'">
            <i class="fa-solid fa-triangle-exclamation"></i> Atenção <span class="bg-rose-100 text-rose-700 px-1.5 rounded text-[10px]"><?php echo $countCritical; ?></span>
        </button>
        <button @click="activeTab = 'ok'" 
                class="px-6 py-3 border-b-2 font-bold text-sm transition-all flex items-center gap-2 whitespace-nowrap"
                :class="activeTab === 'ok' ? 'text-emerald-600 border-emerald-600 bg-emerald-50 rounded-t-lg' : 'text-slate-500 border-transparent hover:text-slate-700'">
            <i class="fa-solid fa-check-circle"></i> Em Dia
        </button>
        <button @click="activeTab = 'todos'" 
                class="px-6 py-3 border-b-2 font-bold text-sm transition-all flex items-center gap-2 whitespace-nowrap"
                :class="activeTab === 'todos' ? 'text-indigo-600 border-indigo-600 bg-indigo-50 rounded-t-lg' : 'text-slate-500 border-transparent hover:text-slate-700'">
            <i class="fa-solid fa-list"></i> Todos
        </button>
    </div>

    <!-- Grid de Categorias -->
    <?php if(empty($categoryData)): ?>
        <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-300">
            <i class="fa-solid fa-tags text-4xl text-slate-300 mb-4"></i>
            <p class="text-slate-500">Nenhuma categoria de despesa encontrada.</p>
        </div>
    <?php else: ?>
    <form id="budget-form" action="index.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <input type="hidden" name="action" value="save_budget">
        <?php foreach ($categoryData as $cat): ?>
        <div x-data="{ active: <?php echo $cat['ativo'] ? 'true' : 'false'; ?>, scope: '<?php echo $cat['escopo']; ?>' }"
             x-show="(activeTab === 'todos') || (activeTab === 'atencao' && ('<?php echo $cat['status']; ?>' === 'critical' || '<?php echo $cat['status']; ?>' === 'warning')) || (activeTab === 'ok' && '<?php echo $cat['status']; ?>' === 'ok')"
             x-transition
             class="bg-white rounded-2xl border border-slate-200 p-5 hover:shadow-lg transition-all duration-300 group relative flex flex-col"
             :class="!active ? 'bg-slate-50 border-dashed' : ''">
            
            <div class="flex justify-between items-start mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl <?php echo $cat['iconBg']; ?> flex items-center justify-center text-lg shadow-sm transition-colors duration-300"
                         :class="!active ? 'grayscale opacity-50' : ''">
                        <i class="fa-solid <?php echo $cat['icone']; ?>"></i>
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-800 leading-tight line-clamp-1" title="<?php echo htmlspecialchars($cat['nome']); ?>"><?php echo htmlspecialchars($cat['nome']); ?></h4>
                        <p class="text-[10px] font-bold uppercase text-slate-400 tracking-wide"><?php echo htmlspecialchars($cat['grupo']); ?></p>
                    </div>
                </div>
                <!-- Toggle de Ativo/Inativo -->
                <div class="flex items-center">
                    <input type="hidden" name="ativo[<?php echo $cat['id']; ?>]" :value="active ? 1 : 0">
                    <button type="button" @click="active = !active" 
                            class="relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-white/75"
                            :class="active ? 'bg-indigo-600' : 'bg-slate-200'"
                            title="Monitorar esta categoria">
                        <span class="sr-only">Monitorar</span>
                        <span aria-hidden="true" 
                              class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow-lg ring-0 transition duration-200 ease-in-out"
                              :class="active ? 'translate-x-4' : 'translate-x-0'"></span>
                    </button>
                </div>
            </div>
            
            <div class="mb-5 flex-1" :class="!active ? 'opacity-50' : ''">
                <div class="flex justify-between items-end mb-1.5">
                    <span class="text-xs font-bold text-slate-500">Gasto: <span class="<?php echo $cat['progress'] >= 100 ? 'text-rose-600' : 'text-slate-700'; ?> transition-colors"><?php echo format_currency($cat['gasto'], $CURRENT_USER['moeda']); ?></span></span>
                    <span class="text-xs font-black <?php echo $cat['textColor']; ?>"><?php echo round($cat['progress']); ?>%</span>
                </div>
                <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                    <div class="<?php echo $cat['barColor']; ?> h-2.5 rounded-full transition-all duration-700 ease-out shadow-sm" style="width: <?php echo min($cat['progress'], 100); ?>%"></div>
                </div>
            </div>
            
            <div class="relative pt-2 mt-auto border-t border-slate-50 flex items-center justify-between gap-3">
                <div class="relative flex-1">
                    <label class="absolute -top-1 left-0 text-[10px] font-bold text-slate-400 uppercase tracking-wider pointer-events-none">Meta Mensal</label>
                    <div class="relative group-focus-within:scale-105 transition-transform origin-left">
                        <span class="absolute left-0 top-1/2 -translate-y-1/2 text-slate-400 text-xs font-bold"><?php echo $CURRENT_USER['moeda']; ?></span>
                        <input type="text" 
                               oninput="formatMoney(this)" 
                               name="orcamento[<?php echo $cat['id']; ?>]" 
                               value="<?php echo $cat['orcado'] ? number_format($cat['orcado'], 2, ',', '.') : ''; ?>" 
                               placeholder="0,00" 
                               :readonly="!active"
                               :class="!active ? 'text-slate-400 bg-transparent' : 'text-slate-700'"
                               class="w-full pl-8 pr-2 py-1 bg-transparent border-b border-slate-200 text-sm font-bold focus:border-indigo-500 outline-none transition-all">
                    </div>
                </div>
                
                <!-- Selector de Escopo (Personal/Family) -->
                <div class="relative">
                    <input type="hidden" name="escopo[<?php echo $cat['id']; ?>]" :value="scope">
                    <button type="button" 
                            @click="scope = (scope === 'FAMILIA' ? 'PESSOAL' : 'FAMILIA')" 
                            :disabled="!active"
                            class="flex items-center gap-1.5 px-2 py-1 rounded text-[10px] font-bold uppercase border transition-colors"
                            :class="scope === 'FAMILIA' ? 'bg-indigo-50 text-indigo-600 border-indigo-100' : 'bg-sky-50 text-sky-600 border-sky-100'"
                            :title="scope === 'FAMILIA' ? 'Orçamento da Casa (Todos)' : 'Orçamento Pessoal (Meu)'">
                        <i class="fa-solid" :class="scope === 'FAMILIA' ? 'fa-house' : 'fa-user'"></i>
                        <span x-text="scope === 'FAMILIA' ? 'Casa' : 'Meu'"></span>
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </form>
    
    <!-- Empty State para abas vazias -->
    <div x-show="activeTab === 'atencao' && <?php echo $countCritical; ?> === 0" class="text-center py-12 bg-white rounded-2xl border-2 border-dashed border-slate-200">
        <div class="w-16 h-16 bg-emerald-50 text-emerald-500 rounded-full flex items-center justify-center text-3xl mx-auto mb-4"><i class="fa-solid fa-thumbs-up"></i></div>
        <h3 class="text-lg font-bold text-slate-700">Tudo sob controle!</h3>
        <p class="text-slate-400 text-sm">Nenhum orçamento estourado ou em alerta neste mês.</p>
    </div>
    <?php endif; ?>
</div>
