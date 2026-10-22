
<?php
// Busca recorrentes (que não são 'UNICA') E que NÃO pertencem a Veículos/Pets
// CORREÇÃO: Adicionado filtro AND l.tipo = 'DESPESA' para evitar que salários apareçam aqui
$stmt = $pdo->prepare("
    SELECT l.*, c.nome as categoria_nome, c.grupo as categoria_grupo, c.icone as categoria_icone
    FROM lancamentos l 
    LEFT JOIN categorias c ON l.categoria_id = c.id 
    WHERE l.familia_id = ? 
    AND l.tipo = 'DESPESA'
    AND l.tipo_recorrencia != 'UNICA' 
    AND l.deleted_at IS NULL 
    AND (c.grupo IS NULL OR c.grupo NOT IN ('Veículos', 'Pets'))
    ORDER BY l.valor DESC
");
$stmt->execute([$LOGGED_IN_FAMILY_ID]);
$subscriptions = $stmt->fetchAll();

$stmt_cats = $pdo->prepare("SELECT id, nome, tipo FROM categorias WHERE familia_id = ? AND deleted_at IS NULL ORDER BY tipo, nome");
$stmt_cats->execute([$LOGGED_IN_FAMILY_ID]);
$categories = $stmt_cats->fetchAll();
$categoriesJson = json_encode($categories);

// Preparação de dados para o Dash (Gráfico e KPIs)
$categoryStats = [];
$totalMensal = 0;
$totalAnualProjection = 0;

// Paleta de cores vibrante para variação no gráfico
$colors = [
    '#6366f1', // Indigo
    '#ec4899', // Pink
    '#10b981', // Emerald
    '#f59e0b', // Amber
    '#ef4444', // Red
    '#8b5cf6', // Violet
    '#06b6d4', // Cyan
    '#f97316', // Orange
    '#84cc16', // Lime
    '#14b8a6', // Teal
];
$colorIndex = 0;

foreach ($subscriptions as $sub) {
    $cat = $sub['categoria_nome'] ?? 'Sem Categoria';
    $catIcon = $sub['categoria_icone'] ?? 'fa-rss';
    
    // Normalização para valor mensal
    $monthlyValue = 0;
    if ($sub['tipo_recorrencia'] === 'ANUAL') {
        $monthlyValue = $sub['valor'] / 12;
    } elseif ($sub['tipo_recorrencia'] === 'SEMANAL') {
        $monthlyValue = $sub['valor'] * 4;
    } else { // MENSAL
        $monthlyValue = $sub['valor'];
    }

    if (!isset($categoryStats[$cat])) {
        $categoryStats[$cat] = [
            'value' => 0, 
            'count' => 0, 
            'icon' => $catIcon,
            'color' => $colors[$colorIndex % count($colors)] // Atribui cor cíclica
        ];
        $colorIndex++;
    }
    $categoryStats[$cat]['value'] += $monthlyValue;
    $categoryStats[$cat]['count']++;
    
    $totalMensal += $monthlyValue;
    
    // Projeção anual exata baseada na recorrência
    if ($sub['tipo_recorrencia'] === 'ANUAL') {
        $totalAnualProjection += $sub['valor'];
    } elseif ($sub['tipo_recorrencia'] === 'SEMANAL') {
        $totalAnualProjection += $sub['valor'] * 52;
    } else {
        $totalAnualProjection += $sub['valor'] * 12;
    }
}

// Ordenar categorias por valor
uasort($categoryStats, function($a, $b) {
    return $b['value'] <=> $a['value'];
});

$chartLabels = array_keys($categoryStats);
$chartValues = array_column($categoryStats, 'value');
$chartColors = array_column($categoryStats, 'color'); // Extrai cores para o JS
?>
<div x-data="subscriptionsController()">
    <div class="space-y-8 animate-fade-in">
        <!-- Header -->
        <div class="flex justify-between items-center">
            <div>
                <h2 class="text-xl font-bold text-slate-800">Assinaturas e Serviços</h2>
                <p class="text-sm text-slate-500">Gerencie seus pagamentos fixos recorrentes (Despesas).</p>
            </div>
            <button @click="openModal('add')" class="px-4 py-2 bg-indigo-600 text-white rounded-lg font-bold text-xs hover:bg-indigo-700 shadow-lg shadow-indigo-600/20 flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">NOVA ASSINATURA</span>
            </button>
        </div>

        <!-- Dashboard Row -->
        <?php if (!empty($subscriptions)): ?>
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            <!-- Stats (KPIs) - Esquerda -->
            <div class="lg:col-span-4 flex flex-col gap-4">
                <div class="bg-indigo-600 p-6 rounded-2xl shadow-lg shadow-indigo-200 text-white relative overflow-hidden group">
                     <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -mr-10 -mt-10 blur-2xl group-hover:scale-110 transition-transform"></div>
                     <div class="relative z-10">
                         <div class="flex items-center gap-3 mb-2 opacity-80">
                             <div class="w-8 h-8 rounded-lg bg-white/20 flex items-center justify-center"><i class="fa-solid fa-wallet"></i></div>
                             <p class="text-xs font-bold uppercase tracking-wider">Custo Mensal Médio</p>
                         </div>
                         <h3 class="text-4xl font-black"><?php echo format_currency($totalMensal, $CURRENT_USER['moeda']); ?></h3>
                         <p class="text-xs mt-2 opacity-70">Impacto mensal no orçamento (inclui anuais/12).</p>
                     </div>
                </div>
                <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm relative overflow-hidden flex-1">
                     <p class="text-xs text-emerald-600 font-bold uppercase tracking-wider mb-1">Projeção Anual Total</p>
                     <h3 class="text-3xl font-black text-slate-800"><?php echo format_currency($totalAnualProjection, $CURRENT_USER['moeda']); ?></h3>
                     <p class="text-xs text-slate-400 mt-2">Soma de todos os contratos em 12 meses.</p>
                </div>
            </div>

            <!-- Gráfico e Detalhamento - Direita -->
            <div class="lg:col-span-8 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm flex flex-col md:flex-row gap-8">
                <div class="md:w-1/2 flex flex-col justify-center items-center">
                    <h3 class="font-bold text-slate-800 mb-4 w-full text-left">Distribuição do Gasto</h3>
                    <div class="w-full h-48 relative">
                        <canvas id="subsChart"></canvas>
                    </div>
                </div>
                <div class="md:w-1/2 border-l border-slate-100 pl-0 md:pl-8 pt-6 md:pt-0">
                    <h4 class="text-xs font-bold text-slate-400 uppercase mb-4 tracking-wider">Por Categoria (Mensal)</h4>
                    <div class="space-y-3 max-h-56 overflow-y-auto custom-scrollbar pr-2">
                        <?php foreach($categoryStats as $catName => $stat): 
                            $percent = ($stat['value'] / $totalMensal) * 100;
                        ?>
                        <div class="flex items-center justify-between group">
                            <div class="flex items-center gap-3">
                                <!-- Ícone com cor dinâmica -->
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center text-xs transition-colors"
                                     style="background-color: <?php echo $stat['color']; ?>20; color: <?php echo $stat['color']; ?>">
                                    <i class="fa-solid <?php echo $stat['icon']; ?>"></i>
                                </div>
                                <div>
                                    <p class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($catName); ?></p>
                                    <p class="text-[10px] text-slate-400"><?php echo $stat['count']; ?> serviço(s)</p>
                                </div>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-bold text-slate-800"><?php echo format_currency($stat['value'], $CURRENT_USER['moeda']); ?></p>
                                <p class="text-[10px] font-bold" style="color: <?php echo $stat['color']; ?>"><?php echo round($percent, 1); ?>%</p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Lista de Assinaturas -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($subscriptions as $sub): 
                $isAnnual = $sub['tipo_recorrencia'] === 'ANUAL';
                $monthlyEquiv = $isAnnual ? ($sub['valor'] / 12) : $sub['valor'];
                // Busca a cor da categoria para o card também
                $cardColor = $categoryStats[$sub['categoria_nome'] ?? 'Sem Categoria']['color'] ?? '#6366f1';
            ?>
            <div class="bg-white rounded-2xl border border-slate-200 p-5 flex flex-col relative group hover:shadow-lg hover:-translate-y-1 transition-all duration-300">
                <div class="flex justify-between items-start mb-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center text-lg" style="background-color: <?php echo $cardColor; ?>20; color: <?php echo $cardColor; ?>">
                            <i class="fa-solid <?php echo $sub['categoria_icone'] ?? 'fa-rss'; ?>"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-800 text-sm leading-tight line-clamp-1" title="<?php echo htmlspecialchars($sub['descricao']); ?>"><?php echo htmlspecialchars($sub['descricao']); ?></h3>
                            <p class="text-[10px] font-bold uppercase tracking-wide" style="color: <?php echo $cardColor; ?>"><?php echo htmlspecialchars($sub['categoria_nome']); ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <?php if($isAnnual): ?>
                            <p class="font-bold text-sm text-slate-800"><?php echo format_currency($sub['valor'], $CURRENT_USER['moeda']); ?></p>
                            <span class="text-[10px] text-slate-400 font-medium bg-slate-100 px-1.5 py-0.5 rounded">/ano</span>
                        <?php else: ?>
                            <p class="font-bold text-sm text-slate-800"><?php echo format_currency($sub['valor'], $CURRENT_USER['moeda']); ?></p>
                            <span class="text-[10px] text-slate-400 font-medium bg-slate-100 px-1.5 py-0.5 rounded">/mês</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if($isAnnual): ?>
                <div class="bg-amber-50 rounded-lg p-2 mb-4 flex items-center justify-between border border-amber-100">
                    <span class="text-[10px] font-bold text-amber-700 uppercase">Equivalente Mensal</span>
                    <span class="text-xs font-bold text-amber-800"><?php echo format_currency($monthlyEquiv, $CURRENT_USER['moeda']); ?></span>
                </div>
                <?php else: ?>
                <div class="h-9 mb-4"></div> <!-- Spacer para alinhamento -->
                <?php endif; ?>
                
                <div class="mt-auto pt-4 border-t border-slate-50 flex justify-between items-center text-xs">
                    <span class="text-slate-400">Próx: <strong class="text-slate-600"><?php echo date('d/m/Y', strtotime($sub['data_competencia'])); ?></strong></span>
                    
                    <div class="flex gap-3 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button @click='openModal("edit", <?php echo json_encode($sub); ?>)' class="text-indigo-500 hover:text-indigo-700 font-bold" title="Editar"><i class="fa-solid fa-pen"></i></button>
                        <form action="index.php" method="POST" onsubmit="return confirm('Cancelar assinatura?');">
                            <input type="hidden" name="action" value="delete_transaction">
                            <input type="hidden" name="transaction_id" value="<?php echo $sub['id']; ?>">
                            <input type="hidden" name="redirect_to" value="subscriptions">
                            <button type="submit" class="text-red-400 hover:text-red-600 font-bold" title="Cancelar"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($subscriptions)): ?>
                <div class="col-span-full py-16 text-center text-slate-400 bg-white rounded-2xl border-2 border-dashed border-slate-200">
                    <div class="w-16 h-16 bg-slate-50 rounded-full flex items-center justify-center mx-auto mb-4 text-2xl"><i class="fa-solid fa-calendar-xmark"></i></div>
                    <p class="font-medium text-slate-600">Nenhuma assinatura de serviço ativa.</p>
                    <p class="text-xs mt-1 max-w-xs mx-auto">Cadastre seus streamings, internet e mensalidades para gerenciar o custo fixo.</p>
                    <button @click="openModal('add')" class="mt-4 text-indigo-600 font-bold text-sm hover:underline">Adicionar Agora</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Nova/Editar Assinatura -->
    <div x-show="modal.open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
        <form action="index.php" method="POST" class="bg-white rounded-3xl p-8 w-full max-w-md space-y-5 shadow-2xl" @click.away="closeModal()">
            <input type="hidden" name="action" :value="modal.isEdit ? 'edit_transaction' : 'add_transaction'">
            <input type="hidden" name="transaction_id" :value="modal.id">
            <input type="hidden" name="redirect_to" value="subscriptions"> 
            <input type="hidden" name="status" value="PENDENTE">
            
            <div class="flex justify-between items-center pb-2 border-b border-slate-100">
                <h3 class="font-bold text-xl text-slate-800" x-text="modal.isEdit ? 'Editar Assinatura' : 'Nova Assinatura'"></h3>
                <button type="button" @click="closeModal()" class="text-slate-400 hover:text-slate-600 text-xl transition-colors"><i class="fa-solid fa-times"></i></button>
            </div>
            
            <div>
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Serviço / Descrição</label>
                <input type="text" name="descricao" x-model="form.descricao" placeholder="Ex: Netflix, Academia" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 font-bold text-slate-700 focus:border-indigo-500 focus:ring-4 focus:ring-indigo-500/10 transition-all outline-none" required>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Valor</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold text-sm">R$</span>
                        <input type="text" name="valor" x-model="form.valor" oninput="formatMoney(this)" placeholder="0,00" class="w-full pl-10 p-4 border border-slate-200 rounded-xl mt-1.5 font-bold text-slate-700 outline-none focus:border-indigo-500" required>
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Frequência</label>
                    <select name="recorrencia" x-model="form.recorrencia" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 bg-white text-slate-700 font-medium outline-none focus:border-indigo-500">
                        <option value="MENSAL">Mensal</option>
                        <option value="ANUAL">Anual</option>
                        <option value="SEMANAL">Semanal</option>
                    </select>
                </div>
            </div>
            
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Próximo Vencimento</label>
                    <input type="date" name="data_competencia" x-model="form.data_competencia" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 text-slate-700 outline-none focus:border-indigo-500" required>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Tipo</label>
                    <!-- CORREÇÃO: Tipo bloqueado em Despesa para evitar conflitos de lista -->
                    <select name="tipo" x-model="form.tipo" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 bg-slate-100 text-slate-500 font-medium outline-none pointer-events-none">
                        <option value="DESPESA">Despesa</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Categoria</label>
                <select name="categoria_id" x-model="form.categoria_id" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 bg-white text-slate-700 font-medium outline-none focus:border-indigo-500" required>
                    <option value="">Selecione...</option>
                    <template x-for="cat in categories" :key="cat.id">
                        <option :value="cat.id" x-text="cat.nome"></option>
                    </template>
                </select>
            </div>

            <div class="flex gap-3 pt-4">
                <button type="button" @click="closeModal()" class="flex-1 py-4 bg-slate-100 rounded-xl font-bold text-slate-600 hover:bg-slate-200 transition-colors">Cancelar</button>
                <button type="submit" class="flex-[2] py-4 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 shadow-lg shadow-indigo-600/20 active:scale-95 transition-all">SALVAR ASSINATURA</button>
            </div>
        </form>
    </div>
</div>

<!-- Chart Script -->
<script>
const subscriptionCategories = <?php echo $categoriesJson; ?>;

function subscriptionsController() {
    return {
        modal: { open: false, isEdit: false, id: null },
        form: {
            descricao: '',
            valor: '',
            recorrencia: 'MENSAL',
            data_competencia: new Date().toISOString().slice(0, 10),
            tipo: 'DESPESA',
            categoria_id: ''
        },
        categories: subscriptionCategories,

        openModal(mode, data = null) {
            this.modal.isEdit = mode === 'edit';
            if (this.modal.isEdit && data) {
                this.modal.id = data.id;
                this.form.descricao = data.descricao;
                // Formata Valor
                this.form.valor = data.valor ? new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(data.valor) : '';
                
                this.form.recorrencia = data.tipo_recorrencia || 'MENSAL';
                this.form.data_competencia = data.data_competencia;
                this.form.tipo = 'DESPESA'; // Força despesa
                this.form.categoria_id = data.categoria_id;
            } else {
                this.modal.id = null;
                this.resetForm();
            }
            this.modal.open = true;
        },

        closeModal() {
            this.modal.open = false;
        },

        resetForm() {
            this.form = {
                descricao: '',
                valor: '',
                recorrencia: 'MENSAL',
                data_competencia: new Date().toISOString().slice(0, 10),
                tipo: 'DESPESA',
                categoria_id: ''
            };
        }
    }
}

document.addEventListener('alpine:init', () => {
    const ctx = document.getElementById('subsChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($chartLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($chartValues); ?>,
                    backgroundColor: <?php echo json_encode($chartColors); ?>, // Cores dinâmicas do PHP
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            boxWidth: 8,
                            font: { size: 10, family: 'Inter' },
                            padding: 15
                        },
                        display: false // Oculta legenda padrão do chart.js pois fizemos customizada
                    }
                },
                cutout: '75%'
            }
        });
    }
});
</script>
