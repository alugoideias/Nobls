
<?php
// --- REGRAS DE PRIVACIDADE ---
// Filtra ativos baseado na visibilidade do dono do ativo
$privacyCondition = " AND (t.usuario_id = $LOGGED_IN_USER_ID OR u.is_restricted = 0) ";

// Busca de Dados com Join em Usuários para checar restrição
$sqlAccounts = "SELECT t.* FROM contas_bancarias t JOIN usuarios u ON t.usuario_id = u.id WHERE t.familia_id = $LOGGED_IN_FAMILY_ID AND t.deleted_at IS NULL $privacyCondition";
$accounts = $pdo->query($sqlAccounts)->fetchAll();

$sqlCards = "SELECT t.* FROM cartoes_credito t JOIN usuarios u ON t.usuario_id = u.id WHERE t.familia_id = $LOGGED_IN_FAMILY_ID AND t.deleted_at IS NULL $privacyCondition";
$cards = $pdo->query($sqlCards)->fetchAll();

// Veículos e Imóveis
$vehicles = $pdo->query("SELECT * FROM ativos_veiculos WHERE familia_id = $LOGGED_IN_FAMILY_ID AND deleted_at IS NULL")->fetchAll();
$residences = $pdo->query("SELECT * FROM ativos_residencias WHERE familia_id = $LOGGED_IN_FAMILY_ID AND deleted_at IS NULL")->fetchAll();

// Categorias com Grupo (para o modal de adicionar despesa ao ativo)
$stmtCats = $pdo->prepare("SELECT id, nome, grupo FROM categorias WHERE familia_id = ? AND tipo = 'DESPESA' AND deleted_at IS NULL ORDER BY grupo, nome");
$stmtCats->execute([$LOGGED_IN_FAMILY_ID]);
$categories = $stmtCats->fetchAll();

// Agrupa Categorias
$groupedCats = [];
foreach($categories as $cat) {
    $g = $cat['grupo'] ?: 'Geral';
    $groupedCats[$g][] = $cat;
}
ksort($groupedCats);

// Cálculos de Totais
$total_accounts = array_sum(array_column($accounts, 'saldo_atual'));
$total_vehicles = array_sum(array_column($vehicles, 'valor_estimado'));
$total_residences = array_sum(array_column($residences, 'valor_estimado'));
$net_worth = $total_accounts + $total_vehicles + $total_residences;

// Função para calcular eficiência do veículo
function getVehicleStats($pdo, $veiculoId) {
    // Busca abastecimentos que tenham metadados (km e litros)
    $stmt = $pdo->prepare("SELECT meta_dados FROM lancamentos WHERE veiculo_id = ? AND meta_dados IS NOT NULL AND deleted_at IS NULL ORDER BY data_competencia DESC");
    $stmt->execute([$veiculoId]);
    $logs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $totalKm = 0;
    $totalLitros = 0;
    $lastKm = 0;
    $historyCount = 0;
    
    // Simplificação: Calcula média baseada em registros consecutivos
    // Para um cálculo preciso, precisaríamos ordenar por data e calcular delta KM / Litros do abastecimento anterior.
    // Aqui faremos uma estimativa: (Maior KM - Menor KM) / (Soma Litros - Último Abastecimento)
    // Para simplificar no MVP, vamos pegar apenas se houver pelo menos 2 registros.
    
    $parsedLogs = [];
    foreach($logs as $log) {
        $meta = json_decode($log, true);
        if(isset($meta['veiculo_km']) && isset($meta['veiculo_litros']) && $meta['veiculo_km'] > 0 && $meta['veiculo_litros'] > 0) {
            $parsedLogs[] = ['km' => (int)$meta['veiculo_km'], 'litros' => (float)$meta['veiculo_litros']];
        }
    }
    
    // Ordena por KM decrescente (o mais recente primeiro)
    usort($parsedLogs, function($a, $b) { return $b['km'] <=> $a['km']; });
    
    if (count($parsedLogs) >= 2) {
        $maxKm = $parsedLogs[0]['km'];
        $minKm = $parsedLogs[count($parsedLogs)-1]['km'];
        $deltaKm = $maxKm - $minKm;
        
        // Soma litros de todos MENOS o primeiro registro (o mais recente), pois o abastecimento atual "encheu" o tanque para o km futuro, 
        // mas a lógica padrão de média é (KM Atual - KM Anterior) / Litros abastecidos agora (se encheu o tanque).
        // Vamos usar a soma simples dos intervalos: Delta KM Total / Soma Litros (excluindo o abastecimento inicial do minKm, mas como não sabemos qual é, somamos todos menos o mais recente que "fechou" o ciclo pode ser impreciso).
        // Melhor abordagem simples: Delta KM / Soma Litros (exceto o do tanque cheio inicial). Assumindo que sempre enche o tanque.
        
        $sumLitros = 0;
        // Ignora o último registro da lista (o mais antigo, pois ele é o ponto de partida)
        for($i = 0; $i < count($parsedLogs) - 1; $i++) {
            $sumLitros += $parsedLogs[$i]['litros'];
        }
        
        if ($sumLitros > 0) {
            return number_format($deltaKm / $sumLitros, 1, ',', '.') . ' km/L';
        }
    }
    
    return '-- km/L';
}
?>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('assetsPage', () => ({
        tab: 'financial', 
        modal: { open: false, type: '', title: '', isEdit: false, data: {} }, 
        detailModal: { open: false, type: '', data: {} },
        
        openModal(type, data = null) { 
            this.modal.type = type; 
            this.modal.isEdit = !!data; 
            this.modal.title = (data ? 'Editar ' : 'Novo ') + (type === 'vehicle' ? 'Veículo' : (type === 'residence' ? 'Imóvel' : (type === 'account' ? 'Conta' : 'Cartão'))); 
            
            // Clone e formata valores para exibição no input
            let cleanData = data ? {...data} : {};
            
            // Formatação Monetária BR
            if(data) {
                if(cleanData.saldo_atual !== undefined) cleanData.saldo_atual = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(cleanData.saldo_atual);
                if(cleanData.limite_total !== undefined) cleanData.limite_total = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(cleanData.limite_total);
                if(cleanData.valor_estimado !== undefined) cleanData.valor_estimado = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(cleanData.valor_estimado);
            } else {
                // Valores padrão para novos itens
                cleanData.tipo = 'CONTA_CORRENTE'; // Default para conta
            }

            this.modal.data = cleanData; 
            this.modal.open = true; 
        },
        
        openDetailModal(type, data) { 
            this.detailModal.type = type; 
            this.detailModal.data = data; 
            this.detailModal.open = true; 
            this.loadHistory(type, data.id); 
        },
        
        loadHistory(type, id) {
            const container = document.getElementById('asset-history-container');
            container.innerHTML = '<p class="text-center text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin"></i> Buscando histórico...</p>';
            fetch(`data.php?action=get_asset_history&type=${type}&id=${id}`).then(res => res.json()).then(data => {
                if (!data || data.length === 0) { container.innerHTML = '<div class="text-center py-10 opacity-50"><i class="fa-solid fa-file-invoice-dollar text-4xl mb-2"></i><p>Nenhum gasto registrado ainda.</p></div>'; return; }
                let html = '<div class="relative pl-4 border-l-2 border-slate-100 space-y-8">';
                data.forEach(item => { html += `<div class="relative pl-6"><div class="absolute -left-[9px] top-1 w-4 h-4 bg-white border-4 border-slate-200 rounded-full"></div><div class="flex justify-between items-start"><div><p class="text-xs text-slate-400 font-bold uppercase mb-0.5">${item.data_fmt} • ${item.usuario_nome}</p><h4 class="font-bold text-slate-800">${item.descricao}</h4><p class="text-xs text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full inline-block mt-1">${item.categoria_nome || 'Geral'}</p></div><p class="font-bold text-red-500">- ${item.valor_fmt}</p></div></div>`; });
                html += '</div>';
                container.innerHTML = html;
            });
        }
    }));
});
</script>

<div x-data="assetsPage()">
    <!-- Conteúdo da Página -->
    <div class="space-y-6 animate-fade-in">
        <!-- KPI Principal -->
        <div class="bg-slate-900 text-white p-8 rounded-3xl shadow-xl flex flex-col md:flex-row justify-between items-center relative overflow-hidden">
            <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-600 rounded-full mix-blend-overlay filter blur-3xl opacity-20"></div>
            <div class="relative z-10">
                <p class="text-indigo-300 text-xs font-bold uppercase tracking-widest mb-1">Patrimônio Líquido Estimado</p>
                <h2 class="text-4xl lg:text-5xl font-black"><?php echo format_currency($net_worth, $CURRENT_USER['moeda']); ?></h2>
            </div>
            <div class="relative z-10 flex gap-4 mt-6 md:mt-0 text-center">
                <div><p class="text-[10px] text-slate-400 uppercase font-bold">Líquido (Contas)</p><p class="font-bold text-emerald-400"><?php echo format_currency($total_accounts, $CURRENT_USER['moeda']); ?></p></div>
                <div class="w-px bg-slate-700 h-8 self-center"></div>
                <div><p class="text-[10px] text-slate-400 uppercase font-bold">Imobilizado (Bens)</p><p class="font-bold text-white"><?php echo format_currency($total_vehicles + $total_residences, $CURRENT_USER['moeda']); ?></p></div>
            </div>
        </div>

        <!-- Navegação por Abas -->
        <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-1">
            <button @click="tab = 'financial'" :class="tab === 'financial' ? 'text-indigo-600 border-indigo-600 bg-indigo-50' : 'text-slate-500 border-transparent hover:text-slate-700 hover:bg-slate-50'" class="px-6 py-3 border-b-2 font-bold text-sm transition-all rounded-t-lg flex items-center gap-2"><i class="fa-solid fa-wallet"></i> Bancos & Cartões</button>
            <button @click="tab = 'vehicles'" :class="tab === 'vehicles' ? 'text-indigo-600 border-indigo-600 bg-indigo-50' : 'text-slate-500 border-transparent hover:text-slate-700 hover:bg-slate-50'" class="px-6 py-3 border-b-2 font-bold text-sm transition-all rounded-t-lg flex items-center gap-2"><i class="fa-solid fa-car"></i> Veículos</button>
            <button @click="tab = 'residences'" :class="tab === 'residences' ? 'text-indigo-600 border-indigo-600 bg-indigo-50' : 'text-slate-500 border-transparent hover:text-slate-700 hover:bg-slate-50'" class="px-6 py-3 border-b-2 font-bold text-sm transition-all rounded-t-lg flex items-center gap-2"><i class="fa-solid fa-house"></i> Imóveis</button>
        </div>

        <!-- TAB: Bancos & Cartões -->
        <div x-show="tab === 'financial'" class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Contas -->
            <div>
                <div class="flex justify-between items-center mb-4"><h3 class="font-bold text-slate-800">Contas Bancárias</h3><button @click="openModal('account')" class="text-indigo-600 text-xs font-bold hover:underline">+ ADICIONAR</button></div>
                <div class="space-y-3">
                    <?php if (empty($accounts)): ?>
                        <p class="text-slate-400 text-sm italic">Nenhuma conta visível.</p>
                    <?php else: ?>
                        <?php foreach ($accounts as $a): ?>
                        <div class="bg-white rounded-xl border border-slate-200 p-4 flex items-center gap-4 group relative hover:shadow-md transition-all">
                            <div class="w-10 h-10 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center"><i class="fa-solid fa-building-columns"></i></div>
                            <div class="flex-1">
                                <p class="font-bold text-slate-800 text-sm"><?php echo htmlspecialchars($a['nome_banco']); ?></p>
                                <p class="text-[10px] text-slate-400 font-bold uppercase"><?php echo str_replace('_', ' ', htmlspecialchars($a['tipo'])); ?></p>
                            </div>
                            <div class="text-right" title="Este valor atualiza automaticamente com os lançamentos">
                                <p class="font-bold text-slate-900"><?php echo format_currency($a['saldo_atual'], $CURRENT_USER['moeda']); ?></p>
                                <p class="text-[10px] text-slate-400">Saldo Atualizado</p>
                            </div>
                            
                            <!-- Ações (Edit/Delete) -->
                            <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 p-1 rounded-lg shadow-sm border border-slate-100">
                                <button @click='openModal("account", <?php echo json_encode($a); ?>)' class="w-7 h-7 flex items-center justify-center text-indigo-600 hover:bg-indigo-50 rounded" title="Editar/Corrigir Saldo"><i class="fa-solid fa-pen text-xs"></i></button>
                                <form action="index.php" method="POST" onsubmit="return confirm('Excluir esta conta? O histórico será mantido, mas a conta será arquivada.');" class="inline">
                                    <input type="hidden" name="action" value="delete_asset">
                                    <input type="hidden" name="asset_type" value="contas_bancarias">
                                    <input type="hidden" name="asset_id" value="<?php echo $a['id']; ?>">
                                    <button type="submit" class="w-7 h-7 flex items-center justify-center text-red-500 hover:bg-red-50 rounded" title="Excluir"><i class="fa-solid fa-trash text-xs"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Cartões -->
            <div>
                 <div class="flex justify-between items-center mb-4"><h3 class="font-bold text-slate-800">Cartões de Crédito</h3><button @click="openModal('card')" class="text-indigo-600 text-xs font-bold hover:underline">+ ADICIONAR</button></div>
                 <div class="space-y-3">
                    <?php if (empty($cards)): ?>
                        <p class="text-slate-400 text-sm italic">Nenhum cartão visível.</p>
                    <?php else: ?>
                        <?php foreach ($cards as $c): ?>
                        <div class="bg-gradient-to-br from-slate-800 to-slate-900 text-white rounded-xl p-5 shadow-lg relative group overflow-hidden">
                            <p class="font-bold text-sm"><?php echo htmlspecialchars($c['nome_cartao']); ?></p>
                            <p class="text-[10px] uppercase opacity-70">Limite: <?php echo format_currency($c['limite_total'], $CURRENT_USER['moeda']); ?></p>
                            
                            <!-- Ações (Edit/Delete) -->
                            <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button @click='openModal("card", <?php echo json_encode($c); ?>)' class="w-7 h-7 flex items-center justify-center text-white hover:text-indigo-300 rounded bg-white/10" title="Editar"><i class="fa-solid fa-pen text-xs"></i></button>
                                <form action="index.php" method="POST" onsubmit="return confirm('Excluir este cartão?');" class="inline">
                                    <input type="hidden" name="action" value="delete_asset">
                                    <input type="hidden" name="asset_type" value="cartoes_credito">
                                    <input type="hidden" name="asset_id" value="<?php echo $c['id']; ?>">
                                    <button type="submit" class="w-7 h-7 flex items-center justify-center text-rose-400 hover:text-rose-200 rounded bg-white/10" title="Excluir"><i class="fa-solid fa-trash text-xs"></i></button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                 </div>
            </div>
        </div>

        <!-- TAB: Veículos -->
        <div x-show="tab === 'vehicles'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($vehicles as $v): 
                $efficiency = getVehicleStats($pdo, $v['id']);
            ?>
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden hover:shadow-xl transition-all duration-300 group relative">
                <!-- Ações Flutuantes (Edit/Delete) - Visíveis no Hover -->
                <div class="absolute top-2 right-2 flex gap-1 z-20 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 p-1.5 rounded-lg shadow-sm">
                    <button @click.stop='openModal("vehicle", <?php echo json_encode($v); ?>)' class="w-8 h-8 flex items-center justify-center bg-indigo-50 text-indigo-600 rounded-lg hover:bg-indigo-100 transition-colors" title="Editar Dados"><i class="fa-solid fa-pen"></i></button>
                    <form action="index.php" method="POST" onsubmit="return confirm('Tem certeza? Isso remove o veículo da lista.');" class="inline">
                        <input type="hidden" name="action" value="delete_asset">
                        <input type="hidden" name="asset_type" value="ativos_veiculos">
                        <input type="hidden" name="asset_id" value="<?php echo $v['id']; ?>">
                        <button type="submit" class="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-100 transition-colors" title="Remover"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>

                <!-- Área Clicável para Detalhes -->
                <div class="cursor-pointer" @click='openDetailModal("vehicle", <?php echo json_encode($v); ?>)'>
                    <div class="h-24 bg-gradient-to-r from-blue-500 to-indigo-600 p-6 relative flex items-center justify-center">
                        <i class="fa-solid fa-car text-5xl text-white/90 drop-shadow-lg"></i>
                    </div>
                    <div class="p-5">
                        <h3 class="font-bold text-lg text-slate-800 leading-tight"><?php echo htmlspecialchars($v['descricao']); ?></h3>
                        <p class="text-xs text-slate-500 font-bold uppercase tracking-wide mb-1"><?php echo htmlspecialchars($v['marca'].' • '.$v['modelo']); ?></p>
                        <div class="flex items-center gap-2 mt-2">
                            <?php if(!empty($v['placa'])): ?>
                                <span class="text-[10px] bg-slate-100 text-slate-600 px-2 py-0.5 rounded font-mono border border-slate-200"><?php echo htmlspecialchars($v['placa']); ?></span>
                            <?php endif; ?>
                            <span class="text-[10px] bg-amber-100 text-amber-700 px-2 py-0.5 rounded font-bold border border-amber-200" title="Consumo Médio"><i class="fa-solid fa-gas-pump"></i> <?php echo $efficiency; ?></span>
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-slate-100 flex justify-between items-center">
                            <span class="text-sm font-bold text-slate-700"><?php echo format_currency($v['valor_estimado'], $CURRENT_USER['moeda']); ?></span>
                            <span class="text-xs font-bold text-indigo-600 flex items-center gap-1 group-hover:underline">HISTÓRICO <i class="fa-solid fa-arrow-right"></i></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Botão Novo -->
            <button @click="openModal('vehicle')" class="bg-slate-50 rounded-2xl border-2 border-dashed border-slate-300 flex flex-col items-center justify-center p-6 text-slate-400 hover:text-indigo-600 hover:border-indigo-300 hover:bg-indigo-50 transition-all min-h-[200px]">
                <i class="fa-solid fa-plus text-3xl mb-2"></i>
                <span class="font-bold text-sm">Novo Veículo</span>
            </button>
        </div>

        <!-- TAB: Imóveis -->
        <div x-show="tab === 'residences'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($residences as $r): ?>
            <div class="bg-white rounded-2xl border border-slate-200 overflow-hidden hover:shadow-xl transition-all duration-300 group relative">
                <!-- Ações Flutuantes -->
                <div class="absolute top-2 right-2 flex gap-1 z-20 opacity-0 group-hover:opacity-100 transition-opacity bg-white/90 p-1.5 rounded-lg shadow-sm">
                    <button @click.stop='openModal("residence", <?php echo json_encode($r); ?>)' class="w-8 h-8 flex items-center justify-center bg-emerald-50 text-emerald-600 rounded-lg hover:bg-emerald-100 transition-colors" title="Editar Dados"><i class="fa-solid fa-pen"></i></button>
                    <form action="index.php" method="POST" onsubmit="return confirm('Tem certeza? Isso remove o imóvel da lista.');" class="inline">
                        <input type="hidden" name="action" value="delete_asset">
                        <input type="hidden" name="asset_type" value="ativos_residencias">
                        <input type="hidden" name="asset_id" value="<?php echo $r['id']; ?>">
                        <button type="submit" class="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-100 transition-colors" title="Remover"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div>

                <!-- Área Clicável -->
                <div class="cursor-pointer" @click='openDetailModal("residence", <?php echo json_encode($r); ?>)'>
                    <div class="h-24 bg-gradient-to-r from-emerald-500 to-teal-600 p-6 relative flex items-center justify-center">
                        <i class="fa-solid fa-house-chimney text-5xl text-white/90 drop-shadow-lg"></i>
                    </div>
                    <div class="p-5">
                        <h3 class="font-bold text-lg text-slate-800 leading-tight"><?php echo htmlspecialchars($r['descricao']); ?></h3>
                        <p class="text-xs text-slate-500 font-bold uppercase tracking-wide line-clamp-1"><?php echo htmlspecialchars($r['endereco']); ?></p>
                        
                        <div class="mt-4 pt-4 border-t border-slate-100 flex justify-between items-center">
                            <span class="text-sm font-bold text-slate-700"><?php echo format_currency($r['valor_estimado'], $CURRENT_USER['moeda']); ?></span>
                            <span class="text-xs font-bold text-emerald-600 flex items-center gap-1 group-hover:underline">VER CUSTOS <i class="fa-solid fa-arrow-right"></i></span>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <!-- Botão Novo -->
            <button @click="openModal('residence')" class="bg-slate-50 rounded-2xl border-2 border-dashed border-slate-300 flex flex-col items-center justify-center p-6 text-slate-400 hover:text-emerald-600 hover:border-emerald-300 hover:bg-emerald-50 transition-all min-h-[200px]">
                <i class="fa-solid fa-plus text-3xl mb-2"></i>
                <span class="font-bold text-sm">Novo Imóvel</span>
            </button>
        </div>
    </div>

    <!-- MODAL DE DETALHES (Histórico + Adicionar Despesa) -->
    <div x-show="detailModal.open" x-cloak class="fixed inset-0 z-[100] bg-slate-900/60 flex items-center justify-center p-4 backdrop-blur-sm">
        <div class="bg-white rounded-3xl w-full max-w-4xl max-h-[90vh] flex flex-col md:flex-row overflow-hidden shadow-2xl" @click.away="detailModal.open = false">
            <div class="w-full md:w-1/3 bg-slate-50 p-8 border-r border-slate-200 overflow-y-auto">
                <h3 class="font-black text-2xl text-slate-800 mb-2" x-text="detailModal.data.descricao"></h3>
                <p class="text-sm text-slate-500 font-medium mb-6" x-text="detailModal.type === 'vehicle' ? (detailModal.data.marca + ' ' + detailModal.data.modelo) : detailModal.data.endereco"></p>
                
                <div class="bg-white p-4 rounded-xl border border-slate-200 mb-8 shadow-sm">
                    <p class="text-xs font-bold text-slate-400 uppercase">Valor Atual</p>
                    <p class="text-xl font-black text-slate-800"><span x-text="detailModal.data.valor_estimado"></span></p>
                </div>

                <!-- Formulário de Novo Gasto no Ativo -->
                <div class="border-t border-slate-200 pt-6" x-data="{ expenseForm: { recorrencia: 'UNICA' } }">
                    <h4 class="font-bold text-slate-700 mb-4 flex items-center gap-2"><i class="fa-solid fa-circle-plus text-indigo-600"></i> Adicionar Gasto</h4>
                    <form action="index.php" method="POST" class="space-y-3">
                        <input type="hidden" name="action" value="add_transaction"><input type="hidden" name="tipo" value="DESPESA"><input type="hidden" name="status" value="PAGO"><input type="hidden" name="redirect_to" value="assets">
                        <input type="hidden" name="veiculo_id" :value="detailModal.type === 'vehicle' ? detailModal.data.id : ''"><input type="hidden" name="residencia_id" :value="detailModal.type === 'residence' ? detailModal.data.id : ''">

                        <input type="text" name="descricao" placeholder="Ex: IPVA, Reforma" class="w-full p-3 border rounded-lg text-sm bg-white" required>
                        <div class="flex gap-2"><input type="text" oninput="formatMoney(this)" name="valor" placeholder="Valor" class="w-1/2 p-3 border rounded-lg text-sm bg-white" required><input type="date" name="data_competencia" value="<?php echo date('Y-m-d'); ?>" class="w-1/2 p-3 border rounded-lg text-sm bg-white" required></div>
                        
                        <div class="flex gap-2">
                            <select name="recorrencia" x-model="expenseForm.recorrencia" class="w-full p-3 border rounded-lg text-sm bg-white">
                                <option value="UNICA">Única</option>
                                <option value="PARCELADO">Parcelado</option>
                                <option value="MENSAL">Assinatura Mensal</option>
                                <option value="ANUAL">Assinatura Anual</option>
                            </select>
                            <input x-show="expenseForm.recorrencia === 'PARCELADO'" type="number" name="qtd_parcelas" placeholder="Qtd" class="w-20 p-3 border rounded-lg text-sm bg-white" min="2">
                        </div>

                        <select name="categoria_id" class="w-full p-3 border rounded-lg text-sm bg-white" required>
                            <option value="">Selecione Categoria...</option>
                            <?php foreach($groupedCats as $group => $cats): ?>
                                <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                    <?php foreach($cats as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nome']); ?></option><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="w-full py-3 bg-slate-800 text-white rounded-xl font-bold text-sm hover:bg-slate-900">REGISTRAR DESPESA</button>
                    </form>
                </div>
            </div>
            <div class="flex-1 p-8 bg-white overflow-y-auto relative">
                <button @click="detailModal.open = false" class="absolute top-4 right-4 text-slate-300 hover:text-slate-600 transition-colors"><i class="fa-solid fa-xmark text-2xl"></i></button>
                <h4 class="font-bold text-lg text-slate-800 mb-6 flex items-center gap-2"><i class="fa-solid fa-timeline text-slate-400"></i> Histórico do Ativo</h4>
                <div id="asset-history-container" class="space-y-4"></div>
            </div>
        </div>
    </div>

    <!-- Modais de Cadastro/Edição -->
    <div x-show="modal.open" x-cloak class="fixed inset-0 z-[100] bg-slate-900/40 flex items-center justify-center p-4">
        <form method="POST" action="index.php" class="bg-white rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl" @click.away="modal.open = false">
            <input type="hidden" name="action" :value="modal.isEdit ? 'edit_' + modal.type : 'add_' + modal.type"><input type="hidden" name="id" :value="modal.data.id">
            <h3 class="font-bold text-lg" x-text="modal.title"></h3>
            
            <!-- Template Conta -->
            <template x-if="modal.type === 'account'">
                <div class="space-y-4">
                    <input name="nome_banco" x-model="modal.data.nome_banco" type="text" placeholder="Nome do Banco" class="w-full p-3 border rounded-lg" required>
                    <select name="tipo" x-model="modal.data.tipo" class="w-full p-3 border rounded-lg" required>
                        <option value="CONTA_CORRENTE">Conta Corrente</option>
                        <option value="POUPANCA">Poupança</option>
                        <option value="INVESTIMENTO">Investimento</option>
                    </select>
                    <div>
                        <label class="text-xs font-bold text-slate-500 uppercase">Saldo Atual (Reajuste Manual)</label>
                        <input name="saldo_atual" x-model="modal.data.saldo_atual" type="text" oninput="formatMoney(this)" placeholder="0,00" class="w-full p-3 border rounded-lg" required>
                        <p class="text-[10px] text-slate-400 mt-1 flex items-center gap-1">
                            <i class="fa-solid fa-lightbulb text-yellow-500"></i> 
                            Altere aqui para redefinir o saldo. No dia a dia, use lançamentos de receita/despesa para manter o histórico.
                        </p>
                    </div>
                </div>
            </template>
            
            <!-- Template Cartão -->
            <template x-if="modal.type === 'card'">
                <div class="space-y-4">
                    <input name="nome_cartao" x-model="modal.data.nome_cartao" type="text" placeholder="Nome Cartão (Ex: Visa Infinite)" class="w-full p-3 border rounded-lg" required>
                    <input name="limite_total" x-model="modal.data.limite_total" type="text" oninput="formatMoney(this)" placeholder="Limite Total" class="w-full p-3 border rounded-lg" required>
                    <div class="flex gap-4">
                        <input name="dia_vencimento" x-model="modal.data.dia_vencimento" type="number" placeholder="Dia Vencimento" class="w-full p-3 border rounded-lg" required min="1" max="31">
                        <input name="dia_fechamento" x-model="modal.data.dia_fechamento" type="number" placeholder="Dia Fechamento" class="w-full p-3 border rounded-lg" required min="1" max="31">
                    </div>
                </div>
            </template>
            
            <!-- Template Veículo (COM PLACA E RENAVAM) -->
            <template x-if="modal.type === 'vehicle'">
                <div class="space-y-4">
                    <input name="descricao" x-model="modal.data.descricao" type="text" placeholder="Apelido (Ex: Carro da Família)" class="w-full p-3 border rounded-lg" required>
                    <div class="flex gap-2">
                        <input name="marca" x-model="modal.data.marca" type="text" placeholder="Marca" class="w-1/2 p-3 border rounded-lg">
                        <input name="modelo" x-model="modal.data.modelo" type="text" placeholder="Modelo" class="w-1/2 p-3 border rounded-lg">
                    </div>
                    <div class="flex gap-2">
                        <input name="placa" x-model="modal.data.placa" type="text" placeholder="Placa (ABC-1234)" class="w-1/2 p-3 border rounded-lg uppercase">
                        <input name="renavam" x-model="modal.data.renavam" type="text" placeholder="Renavam" class="w-1/2 p-3 border rounded-lg">
                    </div>
                    <div class="flex gap-2">
                        <input name="ano" x-model="modal.data.ano" type="number" placeholder="Ano" class="w-1/3 p-3 border rounded-lg">
                        <input name="valor_estimado" x-model="modal.data.valor_estimado" type="text" oninput="formatMoney(this)" placeholder="Valor Fipe" class="w-2/3 p-3 border rounded-lg">
                    </div>
                </div>
            </template>
            
            <!-- Template Imóvel -->
            <template x-if="modal.type === 'residence'">
                <div class="space-y-4">
                    <input name="descricao" x-model="modal.data.descricao" type="text" placeholder="Apelido (Ex: Casa de Praia)" class="w-full p-3 border rounded-lg" required>
                    <input name="endereco" x-model="modal.data.endereco" type="text" placeholder="Endereço Completo" class="w-full p-3 border rounded-lg">
                    <input name="valor_estimado" x-model="modal.data.valor_estimado" type="text" oninput="formatMoney(this)" placeholder="Valor de Mercado" class="w-full p-3 border rounded-lg">
                </div>
            </template>
            
            <div class="flex gap-2 pt-2">
                <button type="button" @click="modal.open = false" class="w-full py-3 bg-slate-200 rounded-lg font-bold text-slate-600 hover:bg-slate-300 transition-colors">Cancelar</button>
                <button type="submit" class="w-full py-3 bg-indigo-600 text-white rounded-lg font-bold hover:bg-indigo-700 transition-colors shadow-lg">Salvar</button>
            </div>
        </form>
    </div>
</div>
