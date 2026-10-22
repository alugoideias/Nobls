
<?php
$goals = $pdo->query("SELECT * FROM metas WHERE familia_id = $LOGGED_IN_FAMILY_ID AND deleted_at IS NULL ORDER BY data_limite ASC")->fetchAll();
$accounts = $pdo->query("SELECT id, nome_banco FROM contas_bancarias WHERE familia_id = $LOGGED_IN_FAMILY_ID AND deleted_at IS NULL")->fetchAll();

// Função para calcular esforço mensal
function calculateMonthlyEffort($current, $target, $deadline) {
    if ($current >= $target) return 0;
    
    $today = new DateTime();
    $end = new DateTime($deadline);
    
    // Se já passou do prazo
    if ($today > $end) return ($target - $current); 
    
    $interval = $today->diff($end);
    $months = ($interval->y * 12) + $interval->m + ($interval->d / 30);
    
    if ($months <= 0) $months = 1;
    
    return ($target - $current) / $months;
}

// Configuração de Ícones e Cores por Palavra-Chave (Simulação de IA simples)
function getGoalStyle($name) {
    $name = strtolower($name);
    if (strpos($name, 'viagem') !== false || strpos($name, 'férias') !== false || strpos($name, 'disney') !== false) 
        return ['icon' => 'fa-plane', 'color' => 'indigo'];
    if (strpos($name, 'carro') !== false || strpos($name, 'moto') !== false || strpos($name, 'veículo') !== false) 
        return ['icon' => 'fa-car', 'color' => 'rose'];
    if (strpos($name, 'casa') !== false || strpos($name, 'apt') !== false || strpos($name, 'reforma') !== false) 
        return ['icon' => 'fa-house', 'color' => 'emerald'];
    if (strpos($name, 'emergência') !== false || strpos($name, 'reserva') !== false) 
        return ['icon' => 'fa-shield-heart', 'color' => 'amber'];
    if (strpos($name, 'casamento') !== false || strpos($name, 'festa') !== false) 
        return ['icon' => 'fa-champagne-glasses', 'color' => 'pink'];
    
    return ['icon' => 'fa-bullseye', 'color' => 'slate'];
}
?>
<div x-data="goalsPage()">
    <div class="space-y-6 animate-fade-in">
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-end gap-4 border-b border-slate-200 pb-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Metas & Sonhos</h2>
                <p class="text-slate-500 text-sm">Planejamento financeiro para realizar seus objetivos.</p>
            </div>
            <button @click="openModal()" class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 hover:bg-indigo-700 transition-all active:scale-95 flex items-center gap-2">
                <i class="fa-solid fa-plus"></i> NOVA META
            </button>
        </div>

        <!-- Grid de Metas -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($goals as $goal):
                $progress = $goal['valor_alvo'] > 0 ? ($goal['valor_atual'] / $goal['valor_alvo']) * 100 : 0;
                $isCompleted = $progress >= 100;
                $today = new DateTime();
                $deadline = new DateTime($goal['data_limite']);
                $days_left = $today > $deadline ? 0 : $today->diff($deadline)->days;
                $monthlyNeeded = calculateMonthlyEffort($goal['valor_atual'], $goal['valor_alvo'], $goal['data_limite']);
                
                $style = getGoalStyle($goal['nome']);
                $color = $style['color'];
                $icon = $style['icon'];
                
                // Tailwind classes dinâmicas
                $bgClass = "bg-{$color}-50";
                $textClass = "text-{$color}-600";
                $borderClass = "border-{$color}-100";
                $barClass = "bg-{$color}-500";
            ?>
            <div class="bg-white rounded-3xl border border-slate-200 p-6 flex flex-col relative group overflow-hidden transition-all hover:shadow-xl hover:-translate-y-1">
                
                <!-- Background Icon Faded -->
                <div class="absolute -right-6 -top-6 opacity-5 pointer-events-none">
                    <i class="fa-solid <?php echo $icon; ?> text-9xl <?php echo $textClass; ?>"></i>
                </div>

                <!-- Header Card -->
                <div class="flex justify-between items-start mb-4 relative z-10">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl <?php echo $bgClass; ?> <?php echo $textClass; ?> flex items-center justify-center text-xl shadow-inner">
                            <i class="fa-solid <?php echo $icon; ?>"></i>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg text-slate-800 line-clamp-1 leading-tight"><?php echo htmlspecialchars($goal['nome']); ?></h3>
                            <p class="text-xs text-slate-400 font-bold uppercase mt-0.5">
                                <?php echo $isCompleted ? 'Concluída' : 'Vence em ' . date('d/m/Y', strtotime($goal['data_limite'])); ?>
                            </p>
                        </div>
                    </div>
                    
                    <button @click='openDetailModal(<?php echo json_encode($goal); ?>)' class="text-slate-300 hover:text-indigo-600 transition-colors">
                        <i class="fa-solid fa-ellipsis-vertical text-xl px-2"></i>
                    </button>
                </div>

                <!-- Valores e Progresso -->
                <div class="relative z-10 mb-6">
                    <div class="flex items-end justify-between mb-2">
                        <div>
                             <p class="text-xs text-slate-400 font-bold uppercase">Acumulado</p>
                             <p class="text-2xl font-black text-slate-800"><?php echo format_currency($goal['valor_atual'], $CURRENT_USER['moeda']); ?></p>
                        </div>
                        <div class="text-right">
                             <p class="text-xs text-slate-400 font-bold uppercase">Meta</p>
                             <p class="text-sm font-bold text-slate-600"><?php echo format_currency($goal['valor_alvo'], $CURRENT_USER['moeda']); ?></p>
                        </div>
                    </div>

                    <div class="w-full bg-slate-100 rounded-full h-3 overflow-hidden shadow-inner">
                        <div class="<?php echo $barClass; ?> h-full rounded-full transition-all duration-1000 relative" style="width: <?php echo min($progress, 100); ?>%">
                            <?php if(!$isCompleted): ?><div class="absolute inset-0 bg-white/20 w-full h-full animate-[shimmer_2s_infinite]"></div><?php endif; ?>
                        </div>
                    </div>
                    <div class="flex justify-between mt-1 text-[10px] font-bold">
                        <span class="<?php echo $textClass; ?>"><?php echo round($progress); ?>%</span>
                        <span class="text-slate-400">Faltam <?php echo format_currency($goal['valor_alvo'] - $goal['valor_atual'], $CURRENT_USER['moeda']); ?></span>
                    </div>
                </div>
                
                <!-- Smart Insight / Action -->
                <div class="mt-auto relative z-10">
                    <?php if(!$isCompleted): ?>
                        <div class="bg-slate-50 rounded-xl p-3 mb-3 border border-slate-100 flex items-center gap-3">
                            <div class="bg-white p-2 rounded-lg text-slate-400 shadow-sm"><i class="fa-solid fa-calculator"></i></div>
                            <div>
                                <p class="text-[10px] text-slate-400 uppercase font-bold">Esforço Mensal Sugerido</p>
                                <p class="text-xs font-bold text-slate-700">Guardar <span class="text-indigo-600"><?php echo format_currency($monthlyNeeded, $CURRENT_USER['moeda']); ?></span> / mês</p>
                            </div>
                        </div>
                        <button @click='openAporteModal(<?php echo $goal['id']; ?>, "<?php echo addslashes($goal['nome']); ?>")' class="w-full py-3 bg-slate-900 text-white rounded-xl font-bold text-xs hover:bg-indigo-600 transition-colors shadow-lg flex items-center justify-center gap-2">
                            <i class="fa-solid fa-plus-circle"></i> ADICIONAR APORTE
                        </button>
                    <?php else: ?>
                        <div class="bg-emerald-50 rounded-xl p-4 border border-emerald-100 text-center">
                            <p class="text-emerald-700 font-bold text-sm"><i class="fa-solid fa-trophy mb-1 block text-2xl"></i> Meta Alcançada!</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Empty State -->
            <?php if (empty($goals)): ?>
                <div class="col-span-full py-16 text-center bg-white rounded-3xl border-2 border-dashed border-slate-200">
                    <div class="w-20 h-20 bg-indigo-50 rounded-full flex items-center justify-center text-indigo-300 text-4xl mx-auto mb-4"><i class="fa-solid fa-rocket"></i></div>
                    <h3 class="text-lg font-bold text-slate-700">O que você quer conquistar?</h3>
                    <p class="text-slate-400 text-sm max-w-sm mx-auto mt-2">Viagens, casa própria, carro novo... Crie metas para organizar seus sonhos.</p>
                    <button @click="openModal()" class="mt-6 text-indigo-600 font-bold text-sm hover:underline">Criar primeira meta</button>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Nova/Editar Meta -->
    <div x-show="modal.open" x-cloak class="fixed inset-0 bg-slate-900/40 z-[100] flex items-center justify-center p-4 backdrop-blur-sm">
        <form method="POST" action="index.php" class="bg-white rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl relative" @click.away="modal.open = false">
            <input type="hidden" name="action" :value="modal.isEdit ? 'edit_goal' : 'add_goal'">
            <input type="hidden" name="meta_id" :value="modal.data.id">
            
            <div class="flex justify-between items-center mb-2">
                <h3 class="font-bold text-xl text-slate-800" x-text="modal.isEdit ? 'Editar Meta' : 'Nova Meta'"></h3>
                <button type="button" @click="modal.open = false" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-times text-xl"></i></button>
            </div>
            
            <div><label class="text-xs font-bold text-slate-500 uppercase">Nome do Objetivo</label><input name="nome" x-model="modal.data.nome" type="text" placeholder="Ex: Viagem Disney, Reserva de Emergência" class="w-full p-3 border rounded-xl mt-1 font-medium" required></div>
            <div><label class="text-xs font-bold text-slate-500 uppercase">Valor Alvo (R$)</label><input name="valor_alvo" x-model="modal.data.valor_alvo" type="text" oninput="formatMoney(this)" placeholder="0,00" class="w-full p-3 border rounded-xl mt-1 text-lg font-bold text-slate-700" required></div>
            <div><label class="text-xs font-bold text-slate-500 uppercase">Data Limite</label><input name="data_limite" x-model="modal.data.data_limite" type="date" class="w-full p-3 border rounded-xl mt-1" required></div>
            
            <button type="submit" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 shadow-lg mt-2">SALVAR META</button>
        </form>
    </div>
    
    <!-- Modal Aporte -->
     <div x-show="aporteModal.open" x-cloak class="fixed inset-0 bg-slate-900/40 z-[100] flex items-center justify-center p-4 backdrop-blur-sm">
        <form method="POST" action="index.php" class="bg-white rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl relative" @click.away="aporteModal.open=false">
            <input type="hidden" name="action" value="add_to_goal">
            <input type="hidden" name="meta_id" :value="aporteModal.id">
            
            <div class="flex justify-between items-center mb-2 border-b border-slate-100 pb-2">
                <div>
                    <h3 class="font-bold text-xl text-slate-800">Fazer Aporte</h3>
                    <p class="text-xs text-slate-500">Guardando para: <span x-text="aporteModal.nome" class="font-bold text-indigo-600"></span></p>
                </div>
                <button type="button" @click="aporteModal.open=false" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-times text-xl"></i></button>
            </div>

            <div><label class="text-xs font-bold text-slate-500 uppercase">Valor do Aporte</label><input name="valor" type="text" oninput="formatMoney(this)" placeholder="0,00" class="w-full p-4 border rounded-xl mt-1 text-2xl font-black text-emerald-600 placeholder-slate-300" required autofocus></div>
            <div><label class="text-xs font-bold text-slate-500 uppercase">Data</label><input name="data_aporte" type="date" value="<?php echo date('Y-m-d'); ?>" class="w-full p-3 border rounded-xl mt-1" required></div>
            <div>
                <label class="text-xs font-bold text-slate-500 uppercase">Debitar de (Opcional)</label>
                <select name="conta_origem_id" class="w-full p-3 border rounded-xl bg-white mt-1">
                    <option value="">Apenas registrar (Dinheiro/Externo)</option>
                    <?php foreach($accounts as $acc): ?>
                        <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['nome_banco']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-[10px] text-slate-400 mt-1">Se selecionar uma conta, será criado um lançamento de despesa automaticamente.</p>
            </div>
            
            <button type="submit" class="w-full py-4 bg-emerald-600 text-white rounded-xl font-bold hover:bg-emerald-700 shadow-lg mt-2">CONFIRMAR E GUARDAR</button>
        </form>
     </div>

     <!-- Modal Detalhes e Histórico -->
     <div x-show="detailModal.open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-3xl w-full max-w-2xl max-h-[90vh] flex flex-col shadow-2xl overflow-hidden" @click.away="detailModal.open = false">
            <div class="p-6 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <div>
                    <h3 class="font-bold text-xl text-slate-800" x-text="detailModal.data.nome"></h3>
                    <p class="text-xs text-slate-500">Histórico de contribuições</p>
                </div>
                <div class="flex gap-2">
                    <button @click='openModal(detailModal.data); detailModal.open=false' class="w-8 h-8 rounded-lg bg-white border border-slate-200 text-slate-500 hover:text-indigo-600 flex items-center justify-center"><i class="fa-solid fa-pen"></i></button>
                    <button @click="detailModal.open = false" class="w-8 h-8 rounded-lg bg-white border border-slate-200 text-slate-400 hover:text-slate-600 flex items-center justify-center"><i class="fa-solid fa-times"></i></button>
                </div>
            </div>
            <div class="flex-1 overflow-y-auto p-6 bg-white">
                <div class="grid grid-cols-2 gap-4 mb-6">
                     <div class="p-4 rounded-xl border border-slate-100 bg-slate-50">
                         <p class="text-xs text-slate-400 uppercase font-bold">Total Acumulado</p>
                         <p class="text-2xl font-black text-slate-800" x-text="new Intl.NumberFormat('pt-BR', {style: 'currency', currency: '<?php echo $CURRENT_USER['moeda']; ?>'}).format(detailModal.data.valor_atual)"></p>
                     </div>
                     <div class="p-4 rounded-xl border border-slate-100 bg-slate-50">
                         <p class="text-xs text-slate-400 uppercase font-bold">Faltam</p>
                         <p class="text-2xl font-black text-indigo-600" x-text="new Intl.NumberFormat('pt-BR', {style: 'currency', currency: '<?php echo $CURRENT_USER['moeda']; ?>'}).format(detailModal.data.valor_alvo - detailModal.data.valor_atual)"></p>
                     </div>
                </div>

                <h4 class="font-bold text-sm text-slate-700 mb-4 border-b pb-2">Últimos Aportes</h4>
                <div id="goal-history-list" class="space-y-3">
                    <!-- Preenchido via JS -->
                </div>
            </div>
            <div class="p-4 border-t border-slate-100 bg-slate-50 flex justify-between items-center">
                 <form method="POST" action="index.php" onsubmit="return confirm('ATENÇÃO: Isso excluirá a meta e todo o histórico. Os valores não retornarão para a conta.');">
                    <input type="hidden" name="action" value="delete_goal">
                    <input type="hidden" name="meta_id" :value="detailModal.data.id">
                    <button type="submit" class="text-red-500 text-xs font-bold hover:underline">Excluir Meta</button>
                </form>
                <button @click="detailModal.open=false" class="px-6 py-2 bg-slate-800 text-white rounded-lg font-bold text-sm">Fechar</button>
            </div>
        </div>
     </div>
</div>

<style>
@keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
</style>

<script>
function goalsPage() {
    return {
        modal: { open: false, isEdit: false, data: {} },
        aporteModal: { open: false, id: null, nome: '' },
        detailModal: { open: false, data: {} },
        
        openModal(goal = null) {
            this.modal.isEdit = !!goal;
            let cleanData = goal ? {...goal} : {nome: '', valor_alvo: '', data_limite: ''};
            if(goal && goal.valor_alvo) {
                cleanData.valor_alvo = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(goal.valor_alvo);
            }
            this.modal.data = cleanData;
            this.modal.open = true;
        },
        openAporteModal(id, nome) {
            this.aporteModal.id = id;
            this.aporteModal.nome = nome;
            this.aporteModal.open = true;
        },
        openDetailModal(goal) {
            this.detailModal.data = goal;
            this.detailModal.open = true;
            this.loadHistory(goal.id);
        },
        loadHistory(id) {
            const container = document.getElementById('goal-history-list');
            container.innerHTML = '<p class="text-center text-slate-400 py-4"><i class="fa-solid fa-spinner fa-spin"></i> Carregando...</p>';
            
            fetch(`data.php?action=get_goal_history&meta_id=${id}`)
                .then(res => res.json())
                .then(data => {
                    if (data.length === 0) {
                        container.innerHTML = '<div class="text-center py-8 opacity-50"><i class="fa-solid fa-piggy-bank text-3xl mb-2 text-slate-300"></i><p class="text-sm">Nenhum aporte registrado ainda.</p></div>';
                        return;
                    }
                    let html = '';
                    data.forEach(item => {
                        html += `
                        <div class="flex items-center justify-between p-3 rounded-xl hover:bg-slate-50 border border-transparent hover:border-slate-100 transition-colors">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center"><i class="fa-solid fa-arrow-up"></i></div>
                                <div>
                                    <p class="text-sm font-bold text-slate-800">Aporte Realizado</p>
                                    <p class="text-xs text-slate-500">${item.data_fmt} • por ${item.usuario_nome}</p>
                                </div>
                            </div>
                            <span class="font-bold text-emerald-600">+ ${item.valor_fmt}</span>
                        </div>`;
                    });
                    container.innerHTML = html;
                });
        }
    }
}
</script>