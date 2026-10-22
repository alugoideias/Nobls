
<?php
// --- FILTROS ---
$filter_start = $_GET['start'] ?? date('Y-m-01');
$filter_end = $_GET['end'] ?? date('Y-m-t');
$filter_type = $_GET['type'] ?? '';
$filter_asset = $_GET['asset'] ?? '';

// --- REGRAS DE VISIBILIDADE (HÍBRIDO) ---
$sql = "SELECT l.*, c.nome as categoria_nome, c.icone as categoria_icone, u.nome as usuario_nome, u.foto_perfil as usuario_avatar 
        FROM lancamentos l 
        LEFT JOIN categorias c ON l.categoria_id = c.id 
        JOIN usuarios u ON l.usuario_id = u.id 
        WHERE l.familia_id = ? 
        AND l.deleted_at IS NULL
        AND (
            l.usuario_id = ? 
            OR u.is_restricted = 0 
            OR l.is_shared = 1
            OR l.veiculo_id IS NOT NULL 
            OR l.residencia_id IS NOT NULL
        )";

$params = [$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID];

if ($filter_start) { $sql .= " AND l.data_competencia >= ?"; $params[] = $filter_start; }
if ($filter_end) { $sql .= " AND l.data_competencia <= ?"; $params[] = $filter_end; }
if ($filter_type) { $sql .= " AND l.tipo = ?"; $params[] = $filter_type; }
if ($filter_asset) { $sql .= " AND (l.veiculo_id = ? OR l.residencia_id = ?)"; $params[] = $filter_asset; $params[] = $filter_asset; }

$sql .= " ORDER BY l.data_competencia DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allTransactions = $stmt->fetchAll();

// Separação em Abas (PHP Side para simplificar view)
$transactionsVariable = [];
$transactionsFixed = [];

foreach($allTransactions as $tx) {
    // Regra: Se tem recorrência != UNICA, é fixa/recorrente.
    if ($tx['tipo_recorrencia'] !== 'UNICA') {
        $transactionsFixed[] = $tx;
    } else {
        $transactionsVariable[] = $tx;
    }
}

// Dados para os selects
$stmt_cats = $pdo->prepare("SELECT id, nome, tipo, grupo FROM categorias WHERE familia_id = ? AND deleted_at IS NULL ORDER BY grupo, nome");
$stmt_cats->execute([$LOGGED_IN_FAMILY_ID]);
$categories = $stmt_cats->fetchAll();
$categoriesJson = json_encode($categories);

$stmt_accs = $pdo->prepare("SELECT id, nome_banco FROM contas_bancarias WHERE familia_id = ? AND deleted_at IS NULL");
$stmt_accs->execute([$LOGGED_IN_FAMILY_ID]);
$accounts = $stmt_accs->fetchAll();

$stmt_cards = $pdo->prepare("SELECT id, nome_cartao, dia_fechamento, dia_vencimento FROM cartoes_credito WHERE familia_id = ? AND deleted_at IS NULL");
$stmt_cards->execute([$LOGGED_IN_FAMILY_ID]);
$cards = $stmt_cards->fetchAll();

$stmt_veh = $pdo->prepare("SELECT id, descricao FROM ativos_veiculos WHERE familia_id = ? AND deleted_at IS NULL");
$stmt_veh->execute([$LOGGED_IN_FAMILY_ID]);
$vehicles = $stmt_veh->fetchAll();

$stmt_res = $pdo->prepare("SELECT id, descricao FROM ativos_residencias WHERE familia_id = ? AND deleted_at IS NULL");
$stmt_res->execute([$LOGGED_IN_FAMILY_ID]);
$residences = $stmt_res->fetchAll();

$stmtUsers = $pdo->prepare("SELECT id, nome, foto_perfil FROM usuarios WHERE familia_id = ? AND deleted_at IS NULL");
$stmtUsers->execute([$LOGGED_IN_FAMILY_ID]);
$allFamilyMembers = $stmtUsers->fetchAll();
$allFamilyMembersJson = json_encode($allFamilyMembers);

$allAssets = array_merge(
    array_map(fn($v) => ['id' => $v['id'], 'nome' => $v['descricao'] . ' (Veículo)'], $vehicles),
    array_map(fn($r) => ['id' => $r['id'], 'nome' => $r['descricao'] . ' (Imóvel)'], $residences)
);

// Conta padrão do usuário para pré-seleção
$defaultAccount = $CURRENT_USER['conta_recebimento_id'] ?? '';
?>

<script>
const allCategories = <?php echo $categoriesJson; ?>;
const familyMembers = <?php echo $allFamilyMembersJson; ?>;
const defaultAccountId = '<?php echo $defaultAccount; ?>';

document.addEventListener('alpine:init', () => {
    Alpine.data('transactionsPage', () => ({
        tab: 'variable', 
        modal: {
            show: false,
            isEdit: false,
            initializing: false,
            aiLoading: false,
            form: {},
            commentsHtml: '',
            newComment: '',
            split: {
                active: false,
                participants: []
            }
        },
        settlementModal: { show: false },
        isFuel: false,
        
        getGroups() {
            const filtered = allCategories.filter(c => c.tipo === this.modal.form.tipo);
            const groups = [...new Set(filtered.map(c => c.grupo || 'Geral'))].sort();
            return groups;
        },
        getCatsInGroup(groupName) {
             return allCategories.filter(c => c.tipo === this.modal.form.tipo && (c.grupo || 'Geral') === groupName);
        },
        checkFuelCategory() {
            const cat = allCategories.find(c => c.id == this.modal.form.categoria_id);
            this.isFuel = cat && (cat.nome.toLowerCase().includes('combustível') || cat.nome.toLowerCase().includes('gasolina') || cat.nome.toLowerCase().includes('etanol'));
        },
        init() {
            this.resetForm();
            this.$watch('modal.form.tipo', (value) => {
                // Impede que a lógica rode durante a inicialização (edição)
                if (this.modal.initializing) return;

                // Limpa categoria se mudar o tipo
                const currentCat = allCategories.find(c => c.id == this.modal.form.categoria_id);
                if(currentCat && currentCat.tipo !== value) {
                    this.modal.form.categoria_id = '';
                }
                // Se mudar para RECEITA, limpa o cartão selecionado E pré-seleciona a conta padrão
                if (value === 'RECEITA') {
                    this.modal.form.cartao_id = '';
                    if (!this.modal.isEdit && defaultAccountId) {
                        this.modal.form.conta_id = defaultAccountId;
                    }
                }
                this.checkFuelCategory();
            });
            
            this.$watch('modal.form.categoria_id', () => {
                this.checkFuelCategory();
            });
            
            // Watcher para forçar visibilidade se houver rateio
            this.$watch('modal.split.active', (isActive) => {
                if (isActive) {
                    this.modal.form.is_shared = true;
                }
            });
        },
        resetForm() {
            this.modal.form = {
                id: null,
                descricao: '',
                valor: '',
                tipo: 'DESPESA',
                data_competencia: new Date().toISOString().slice(0, 10),
                status: 'PENDENTE',
                categoria_id: '',
                conta_id: '', // Será preenchido pelo watcher ou manual
                cartao_id: '',
                veiculo_id: '',
                veiculo_km: '',
                veiculo_litros: '',
                residencia_id: '',
                recorrencia: 'UNICA',
                qtd_parcelas: 1,
                is_shared: false
            };
            this.modal.split = { active: false, participants: [] };
            this.modal.commentsHtml = '<p class="text-xs text-slate-400 text-center italic">Nenhum comentário ainda.</p>';
            this.isFuel = false;
        },
        openModal(tx = null) {
            this.modal.initializing = true; // Bloqueia watchers
            this.resetForm();
            
            if (tx) {
                this.modal.isEdit = true;
                const formattedDate = tx.data_competencia; 
                const valorFormatado = tx.valor ? new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(tx.valor) : '';
                const originalCatId = tx.categoria_id || '';
                
                // Parse Meta Dados
                let meta = {};
                try { meta = JSON.parse(tx.meta_dados || '{}'); } catch(e) {}

                this.modal.form = { 
                    ...tx, 
                    valor: valorFormatado,
                    data_competencia: formattedDate,
                    recorrencia: tx.tipo_recorrencia || 'UNICA',
                    qtd_parcelas: tx.parcela_total || 1,
                    veiculo_id: tx.veiculo_id || '',
                    residencia_id: tx.residencia_id || '',
                    conta_id: tx.conta_id || '', // Carrega a conta salva
                    cartao_id: tx.cartao_id || '',
                    is_shared: tx.is_shared == 1,
                    categoria_id: originalCatId,
                    veiculo_km: meta.veiculo_km || '',
                    veiculo_litros: meta.veiculo_litros || ''
                };
                
                // Carregar Rateio se houver
                if(tx.is_shared == 1) {
                    fetch(`data.php?action=get_transaction_splits&id=${tx.id}`)
                    .then(r => r.json())
                    .then(data => {
                        if(data.length > 0) {
                            this.modal.split.active = true;
                            this.modal.split.participants = data.map(p => ({
                                usuario_id: p.usuario_id,
                                valor: new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(p.valor)
                            }));
                        }
                    });
                }

                this.loadComments();
            } else {
                this.modal.isEdit = false;
            }
            
            this.modal.show = true;
            
            // Libera watchers após o DOM atualizar (evita race condition no Select)
            this.$nextTick(() => {
                this.checkFuelCategory();
                this.modal.initializing = false;
            });
        },
        
        // --- LÓGICA DE RATEIO (MANTIDA) ---
        toggleParticipant(userId) {
            const idx = this.modal.split.participants.findIndex(p => p.usuario_id == userId);
            if (idx > -1) {
                this.modal.split.participants.splice(idx, 1);
            } else {
                this.modal.split.participants.push({ usuario_id: userId, valor: '' });
                this.distributeEqually();
            }
        },
        isParticipant(userId) {
            return this.modal.split.participants.some(p => p.usuario_id == userId);
        },
        getParticipantValue(userId) {
            const p = this.modal.split.participants.find(p => p.usuario_id == userId);
            return p ? p.valor : '';
        },
        updateParticipantValue(userId, val) {
            const p = this.modal.split.participants.find(p => p.usuario_id == userId);
            if (p) {
                p.valor = val;
                this.autoAdjust(userId);
            }
        },
        getNumericTotal() {
            let val = this.modal.form.valor.replace(/\./g, '').replace(',', '.');
            return parseFloat(val) || 0;
        },
        getNumericSplitSum() {
            return this.modal.split.participants.reduce((sum, p) => {
                let v = p.valor.replace(/\./g, '').replace(',', '.');
                return sum + (parseFloat(v) || 0);
            }, 0);
        },
        distributeEqually() {
            const total = this.getNumericTotal();
            const count = this.modal.split.participants.length;
            if (count === 0) return;
            const share = total / count;
            this.modal.split.participants.forEach(p => {
                p.valor = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(share);
            });
        },
        autoAdjust(changedUserId) {
            const total = this.getNumericTotal();
            const parts = this.modal.split.participants;
            if (parts.length === 2) {
                const other = parts.find(p => p.usuario_id != changedUserId);
                const changed = parts.find(p => p.usuario_id == changedUserId);
                let valChanged = parseFloat(changed.valor.replace(/\./g, '').replace(',', '.')) || 0;
                let remainder = total - valChanged;
                if (remainder < 0) remainder = 0;
                other.valor = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(remainder);
            }
        },
        getSplitStatus() {
            const total = this.getNumericTotal();
            const sum = this.getNumericSplitSum();
            const diff = total - sum;
            if (Math.abs(diff) < 0.05) return { status: 'ok', msg: 'Total Batendo', color: 'text-emerald-600' };
            if (diff > 0) return { status: 'missing', msg: `Faltam R$ ${diff.toFixed(2)}`, color: 'text-amber-600' };
            return { status: 'over', msg: `Passou R$ ${Math.abs(diff).toFixed(2)}`, color: 'text-rose-600' };
        },

        closeModal() {
            this.modal.show = false;
        },
        openSettlementModal() {
            this.settlementModal.show = true;
        },
        handleFileUpload(event) {
            // ... (Lógica de IA mantida igual)
            const file = event.target.files[0];
            if (!file) return;
            this.modal.aiLoading = true;
            const formData = new FormData();
            formData.append('documento', file);
            fetch('gemini_parser.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.error) { alert('Erro IA: ' + data.error); }
                    else {
                        this.modal.form.descricao = data.descricao || this.modal.form.descricao;
                        if(data.valor) { this.modal.form.valor = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(data.valor); }
                        this.modal.form.data_competencia = data.data_vencimento || this.modal.form.data_competencia;
                        this.modal.form.tipo = data.tipo || 'DESPESA';
                        if(data.categoria_id) this.modal.form.categoria_id = data.categoria_id;
                        if(data.veiculo_id) this.modal.form.veiculo_id = data.veiculo_id;
                        if(data.residencia_id) this.modal.form.residencia_id = data.residencia_id;
                    }
                })
                .catch(err => { console.error(err); alert('Erro ao processar imagem.'); })
                .finally(() => { this.modal.aiLoading = false; });
        },
        loadComments() {
            if(!this.modal.form.id) return;
            fetch('data.php?action=get_comments&transaction_id=' + this.modal.form.id)
                .then(r => r.json())
                .then(data => {
                    if(data.length === 0) {
                        this.modal.commentsHtml = '<p class="text-xs text-slate-400 text-center italic">Nenhum comentário.</p>';
                    } else {
                        this.modal.commentsHtml = data.map(c => `
                            <div class="flex gap-2 items-start">
                                <img src="${c.foto_perfil}" class="w-6 h-6 rounded-full">
                                <div>
                                    <p class="text-xs font-bold text-slate-700">${c.usuario_nome}</p>
                                    <p class="text-xs text-slate-600 bg-white p-2 rounded-lg border border-slate-200 mt-1">${c.comentario}</p>
                                </div>
                            </div>
                        `).join('');
                    }
                });
        },
        addComment() {
            if(!this.modal.newComment.trim() || !this.modal.form.id) return;
            const formData = new FormData();
            formData.append('action', 'add_comment');
            formData.append('lancamento_id', this.modal.form.id);
            formData.append('comentario', this.modal.newComment);
            fetch('index.php', { method: 'POST', body: formData })
                .then(() => { this.modal.newComment = ''; this.loadComments(); });
        }
    }));
});
</script>

<div x-data="transactionsPage()">
    <div class="space-y-6 animate-fade-in">
        <!-- Header Padronizado -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 border-b border-slate-200 pb-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Lançamentos</h2>
                <p class="text-slate-500 text-sm">Gerencie receitas e despesas da dinastia.</p>
            </div>
            <div class="flex gap-3 w-full sm:w-auto">
                <button @click="openSettlementModal()" class="flex-1 sm:flex-none flex items-center justify-center gap-2 px-4 py-3 bg-white text-indigo-600 border border-indigo-200 rounded-xl font-bold text-xs hover:bg-indigo-50 transition-all shadow-sm">
                    <i class="fa-solid fa-hand-holding-dollar"></i> COBRANÇA
                </button>
                <button @click="openModal()" class="flex-[2] sm:flex-none flex items-center justify-center gap-2 px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm hover:bg-indigo-700 shadow-lg shadow-indigo-600/20 transition-all active:scale-95">
                    <i class="fa-solid fa-plus"></i> <span class="hidden sm:inline">NOVO LANÇAMENTO</span><span class="sm:hidden">NOVO</span>
                </button>
            </div>
        </div>

        <!-- Abas de Navegação -->
        <div class="flex gap-4 border-b border-slate-200">
            <button @click="tab = 'variable'" :class="{'text-indigo-600 border-indigo-600 bg-indigo-50': tab === 'variable', 'text-slate-500 border-transparent hover:text-slate-700': tab !== 'variable'}" class="px-6 py-3 border-b-2 font-bold text-sm transition-all rounded-t-lg flex items-center gap-2">
                <i class="fa-solid fa-cart-shopping"></i> Variáveis (Avulsas)
            </button>
            <button @click="tab = 'fixed'" :class="{'text-indigo-600 border-indigo-600 bg-indigo-50': tab === 'fixed', 'text-slate-500 border-transparent hover:text-slate-700': tab !== 'fixed'}" class="px-6 py-3 border-b-2 font-bold text-sm transition-all rounded-t-lg flex items-center gap-2">
                <i class="fa-solid fa-calendar-days"></i> Fixas & Parceladas
            </button>
        </div>

        <!-- Filtros (Simplificado para manter layout) -->
        <form method="GET" action="index.php" class="bg-white p-4 rounded-xl border border-slate-200 shadow-sm flex flex-wrap gap-3 items-end">
            <input type="hidden" name="page" value="transactions">
            <!-- ... Inputs de filtro mantidos idênticos ... -->
            <div class="w-1/2 sm:flex-1 min-w-[120px]"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 block">De</label><input type="date" name="start" value="<?php echo $filter_start; ?>" class="w-full p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-medium focus:border-indigo-500 outline-none"></div>
            <div class="w-[calc(50%-0.75rem)] sm:flex-1 min-w-[120px]"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 block">Até</label><input type="date" name="end" value="<?php echo $filter_end; ?>" class="w-full p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-medium focus:border-indigo-500 outline-none"></div>
            <div class="w-full sm:flex-1 min-w-[140px]"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 block">Tipo</label><select name="type" class="w-full p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-medium focus:border-indigo-500 outline-none"><option value="">Todos</option><option value="RECEITA" <?php echo $filter_type === 'RECEITA' ? 'selected' : ''; ?>>Receita</option><option value="DESPESA" <?php echo $filter_type === 'DESPESA' ? 'selected' : ''; ?>>Despesa</option></select></div>
            <div class="w-full sm:flex-1 min-w-[180px]"><label class="text-[10px] font-bold text-slate-400 uppercase tracking-wider mb-1 block">Patrimônio</label><select name="asset" class="w-full p-2 bg-slate-50 border border-slate-200 rounded-lg text-sm font-medium focus:border-indigo-500 outline-none"><option value="">Todos</option><?php foreach($allAssets as $asset): ?><option value="<?php echo $asset['id']; ?>" <?php echo $filter_asset == $asset['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($asset['nome']); ?></option><?php endforeach; ?></select></div>
            <div class="w-full sm:w-auto flex gap-2 pt-1"><button type="submit" class="flex-1 sm:flex-none px-4 py-2 bg-slate-800 text-white rounded-lg font-bold text-xs hover:bg-slate-700 h-[38px] flex items-center justify-center gap-2"><i class="fa-solid fa-filter"></i> FILTRAR</button><?php if($filter_start != date('Y-m-01') || $filter_type): ?><a href="index.php?page=transactions" class="px-4 py-2 bg-slate-100 text-slate-600 rounded-lg font-bold text-xs hover:bg-slate-200 h-[38px] flex items-center justify-center border border-slate-200" title="Limpar Filtros"><i class="fa-solid fa-xmark"></i></a><?php endif; ?></div>
        </form>

        <!-- Template de Tabela (Reutilizável) -->
        <template x-for="listType in ['variable', 'fixed']">
            <div x-show="tab === listType">
                <?php 
                $jsVar = json_encode($transactionsVariable);
                $jsFix = json_encode($transactionsFixed);
                ?>
                <div class="hidden md:block bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b border-slate-200">
                            <tr>
                                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-wider">Descrição</th>
                                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-wider">Responsável</th>
                                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-wider text-right">Valor</th>
                                <th class="px-6 py-4 text-[10px] font-bold text-slate-400 uppercase tracking-wider text-center">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="tx in (listType === 'variable' ? <?php echo htmlspecialchars($jsVar, ENT_QUOTES, 'UTF-8'); ?> : <?php echo htmlspecialchars($jsFix, ENT_QUOTES, 'UTF-8'); ?>)" :key="tx.id">
                                <tr @click="openModal(tx)" class="cursor-pointer hover:bg-slate-50 transition-colors group">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center text-slate-500 text-lg">
                                                <i class="fa-solid" :class="tx.categoria_icone || 'fa-bag-shopping'"></i>
                                            </div>
                                            <div>
                                                <p class="text-sm font-bold text-slate-800 flex items-center gap-2">
                                                    <span x-text="tx.descricao"></span>
                                                    <span x-show="tx.parcela_total > 1" class="text-[10px] bg-indigo-50 text-indigo-600 border border-indigo-100 px-1.5 py-0.5 rounded font-bold" x-text="tx.parcela_atual + '/' + tx.parcela_total"></span>
                                                    <span x-show="tx.is_shared == 1" class="bg-purple-100 text-purple-700 px-1.5 py-0.5 rounded text-[10px] font-bold border border-purple-200" title="Despesa Dividida"><i class="fa-solid fa-users"></i> Rateio</span>
                                                    <span x-show="tx.is_internal_transfer == 1" class="bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded text-[10px] font-bold border border-gray-200" title="Transferência Interna"><i class="fa-solid fa-arrow-right-arrow-left"></i> P2P</span>
                                                    <span x-show="tx.cartao_id" class="bg-amber-100 text-amber-700 px-1.5 py-0.5 rounded text-[10px] font-bold border border-amber-200" title="Cartão de Crédito"><i class="fa-regular fa-credit-card"></i> Cartão</span>
                                                </p>
                                                <p class="text-xs text-slate-500 mt-0.5"><span x-text="new Date(tx.data_competencia).toLocaleDateString('pt-BR', {timeZone: 'UTC'})"></span> • <span class="font-medium" x-text="tx.categoria_nome || 'Outros'"></span></p>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <img :src="tx.usuario_avatar" class="w-6 h-6 rounded-full border border-slate-200" :title="tx.usuario_nome">
                                            <span class="text-xs font-medium text-slate-600" x-text="tx.usuario_nome"></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <span x-show="tx.status === 'PAGO'" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-emerald-50 text-emerald-600 border border-emerald-100"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Pago</span>
                                        <span x-show="tx.status !== 'PAGO'" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase bg-amber-50 text-amber-600 border border-amber-100"><span class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"></span> Pendente</span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <span class="font-bold text-sm" :class="tx.tipo === 'RECEITA' ? 'text-emerald-600' : 'text-slate-800'" x-text="(tx.tipo === 'DESPESA' ? '-' : '+') + ' ' + new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'}).format(tx.valor)"></span>
                                    </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2">
                                            <button type="button" @click.stop="openModal(tx)" class="w-8 h-8 rounded-lg flex items-center justify-center text-indigo-500 hover:text-indigo-700 hover:bg-indigo-50 transition-all" title="Editar">
                                                <i class="fa-solid fa-pen text-xs"></i>
                                            </button>
                                            <form action="index.php" method="POST" class="inline-block" onsubmit="return confirm('Tem certeza?');" @click.stop>
                                                <input type="hidden" name="action" value="delete_transaction"><input type="hidden" name="transaction_id" :value="tx.id">
                                                <button type="submit" class="w-8 h-8 rounded-lg flex items-center justify-center text-slate-400 hover:text-red-600 hover:bg-red-50 transition-all" title="Excluir"><i class="fa-solid fa-trash-can text-xs"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <!-- Empty State inside table -->
                            <tr x-show="(listType === 'variable' ? <?php echo empty($transactionsVariable) ? 'true' : 'false'; ?> : <?php echo empty($transactionsFixed) ? 'true' : 'false'; ?>)">
                                <td colspan="5" class="py-12 text-center text-slate-400">
                                    <div class="flex flex-col items-center">
                                        <i class="fa-regular fa-folder-open text-2xl mb-2"></i>
                                        <p class="text-xs">Nenhum lançamento nesta categoria.</p>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </template>
    </div>

    <!-- Modal Cobrança (Settlement) - MANTIDO -->
    <div x-show="settlementModal.show" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
        <!-- (Código do modal de cobrança mantido igual, omitido por brevidade no diff se não houve alteração lógica, mas deve estar presente) -->
        <form action="index.php" method="POST" class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl relative" @click.away="settlementModal.show = false">
            <input type="hidden" name="action" value="add_settlement">
            <button type="button" @click="settlementModal.show = false" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 text-xl"><i class="fa-solid fa-times"></i></button>
            <div class="text-center mb-6">
                <div class="w-16 h-16 bg-indigo-50 rounded-full flex items-center justify-center mx-auto mb-3 text-indigo-600 text-3xl"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <h3 class="font-bold text-lg text-slate-800">Criar Cobrança</h3>
                <p class="text-xs text-slate-500">Registre uma dívida ou divisão de conta.</p>
            </div>
            <div class="space-y-4">
                <div><label class="text-xs font-bold text-slate-500 uppercase">Quem deve pagar?</label><select name="devedor_id" class="w-full p-3 border rounded-xl bg-slate-50 mt-1 font-medium" required><option value="">Selecione...</option><?php foreach($familyMembers as $fm): ?><option value="<?php echo $fm['id']; ?>"><?php echo htmlspecialchars($fm['nome']); ?></option><?php endforeach; ?></select></div>
                <div><label class="text-xs font-bold text-slate-500 uppercase">Valor</label><input type="text" name="valor" oninput="formatMoney(this)" placeholder="0,00" class="w-full p-3 border rounded-xl text-lg font-bold text-slate-800" required></div>
                <div><label class="text-xs font-bold text-slate-500 uppercase">Motivo</label><input type="text" name="descricao" placeholder="Ex: Jantar, Gasolina" class="w-full p-3 border rounded-xl" required></div>
                <div><label class="text-xs font-bold text-slate-500 uppercase">Vencimento</label><input type="date" name="data_vencimento" value="<?php echo date('Y-m-d'); ?>" class="w-full p-3 border rounded-xl"></div>
            </div>
            <button type="submit" class="w-full mt-6 py-3 bg-indigo-600 text-white rounded-xl font-bold shadow-lg hover:bg-indigo-700 transition-colors">ENVIAR COBRANÇA</button>
        </form>
    </div>

    <!-- Modal Transação (Atualizado com Rateio e Combustível) -->
    <div x-show="modal.show" @keydown.escape.window="closeModal()" x-cloak class="fixed inset-0 z-[100] flex justify-end">
        <div @click="closeModal()" class="absolute inset-0 bg-slate-900/40 backdrop-blur-sm transition-opacity"></div>
        <div class="relative w-full max-w-lg bg-white shadow-2xl h-full flex flex-col transform transition-transform"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="translate-x-full"
             x-transition:enter-end="translate-x-0"
             x-transition:leave="transition ease-in duration-300"
             x-transition:leave-start="translate-x-0"
             x-transition:leave-end="translate-x-full">
             
            <form action="index.php" method="POST" class="flex flex-col h-full">
                <input type="hidden" name="action" :value="modal.isEdit ? 'edit_transaction' : 'add_transaction'">
                <input type="hidden" name="transaction_id" :value="modal.form.id">
                
                <div class="p-6 border-b flex justify-between items-center bg-slate-50">
                    <h2 class="text-xl font-bold text-slate-800" x-text="modal.isEdit ? 'Editar Lançamento' : 'Novo Lançamento'"></h2>
                    <button type="button" @click="closeModal()" class="text-slate-400 hover:text-slate-600 text-2xl transition-colors">&times;</button>
                </div>
                
                <div class="flex-1 p-6 space-y-4 overflow-y-auto">
                    <!-- AI Import -->
                    <div class="relative group">
                        <button @click.prevent="document.getElementById('ai-importer').click()" type="button" class="w-full py-3 border-2 border-dashed border-indigo-200 bg-indigo-50 text-indigo-600 rounded-xl font-bold text-sm hover:bg-indigo-100 hover:border-indigo-300 transition-all flex items-center justify-center gap-2">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> <span>Preencher com IA (Upload Comprovante)</span>
                        </button>
                        <input type="file" id="ai-importer" @change="handleFileUpload" class="hidden" accept="image/*,.pdf">
                    </div>
                    
                    <!-- Basic Fields -->
                    <div><label class="text-xs font-bold text-slate-500 uppercase">Descrição</label><input x-model="modal.form.descricao" type="text" name="descricao" placeholder="Ex: Mercado Semanal" class="w-full p-3 border rounded-lg mt-1" required></div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-500 uppercase">Valor Total</label>
                            <input x-model="modal.form.valor" type="text" name="valor" placeholder="0,00" class="w-full p-3 border rounded-lg mt-1" required oninput="formatMoney(this)">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 uppercase">Tipo</label>
                            <select x-model="modal.form.tipo" name="tipo" class="w-full p-3 border rounded-lg bg-white mt-1" required>
                                <option value="DESPESA">Despesa</option>
                                <option value="RECEITA">Receita</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-500 uppercase">Data Compra</label><input x-model="modal.form.data_competencia" type="date" name="data_competencia" class="w-full p-3 border rounded-lg mt-1" required></div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 uppercase">Status</label>
                            <select x-model="modal.form.status" name="status" class="w-full p-3 border rounded-lg bg-white mt-1" required>
                                <option value="PENDENTE">Pendente</option>
                                <option value="PAGO">Pago</option>
                            </select>
                        </div>
                    </div>

                    <!-- VINCULAÇÃO DE ATIVO (Veículo/Imóvel) -->
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-500 uppercase">Vincular Veículo</label>
                            <select x-model="modal.form.veiculo_id" name="veiculo_id" class="w-full p-3 border rounded-lg bg-white mt-1">
                                <option value="">Nenhum</option>
                                <?php foreach($vehicles as $v): ?>
                                    <option value="<?php echo $v['id']; ?>"><?php echo htmlspecialchars($v['descricao']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 uppercase">Vincular Imóvel</label>
                            <select x-model="modal.form.residencia_id" name="residencia_id" class="w-full p-3 border rounded-lg bg-white mt-1">
                                <option value="">Nenhum</option>
                                <?php foreach($residences as $r): ?>
                                    <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['descricao']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- RATEIO INTELIGENTE (Omitido lógica de HTML para brevidade, mantido script Alpine) -->
                    <div x-show="modal.form.tipo === 'DESPESA'" class="bg-indigo-50 rounded-xl border border-indigo-100 overflow-hidden shadow-sm transition-all duration-300">
                        <div class="p-4 flex items-center justify-between cursor-pointer hover:bg-indigo-100/50" @click="modal.split.active = !modal.split.active">
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-full bg-indigo-200 text-indigo-700 flex items-center justify-center"><i class="fa-solid fa-users-viewfinder"></i></div>
                                <div>
                                    <p class="text-sm font-bold text-indigo-900">Dividir esta despesa</p>
                                    <p class="text-[10px] text-indigo-600">Atribuir valores específicos a membros.</p>
                                </div>
                            </div>
                            <div class="w-10 h-5 bg-gray-300 rounded-full relative transition-colors duration-200" :class="modal.split.active ? 'bg-indigo-600' : ''">
                                <div class="absolute top-1 left-1 bg-white w-3 h-3 rounded-full transition-transform duration-200" :class="modal.split.active ? 'translate-x-5' : ''"></div>
                            </div>
                        </div>

                        <div x-show="modal.split.active" x-transition class="border-t border-indigo-100 p-4 bg-white/50">
                            <!-- ... Conteúdo Rateio ... -->
                            <p class="text-xs text-slate-500 font-bold mb-3 uppercase">Selecione os participantes:</p>
                            <div class="flex flex-wrap gap-2 mb-4">
                                <template x-for="member in familyMembers" :key="member.id">
                                    <button type="button" @click="toggleParticipant(member.id)" 
                                            class="flex items-center gap-2 px-3 py-1.5 rounded-full border transition-all text-xs font-bold"
                                            :class="isParticipant(member.id) ? 'bg-indigo-100 border-indigo-300 text-indigo-700' : 'bg-white border-slate-200 text-slate-500 hover:border-indigo-200'">
                                        <img :src="member.foto_perfil" class="w-4 h-4 rounded-full">
                                        <span x-text="member.nome"></span>
                                    </button>
                                </template>
                            </div>
                            <div x-show="modal.split.participants.length > 0" class="space-y-2">
                                <template x-for="p in modal.split.participants" :key="p.usuario_id">
                                    <div class="flex items-center justify-between bg-white border border-slate-200 rounded-lg p-2">
                                        <div class="flex items-center gap-2">
                                            <img :src="familyMembers.find(m => m.id == p.usuario_id).foto_perfil" class="w-6 h-6 rounded-full">
                                            <span class="text-xs font-bold text-slate-700" x-text="familyMembers.find(m => m.id == p.usuario_id).nome"></span>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <span class="text-xs text-slate-400">R$</span>
                                            <input type="text" :value="p.valor" @input="updateParticipantValue(p.usuario_id, $event.target.value)" oninput="formatMoney(this)" class="w-20 text-right p-1 border rounded text-xs font-bold outline-none focus:border-indigo-500">
                                        </div>
                                    </div>
                                </template>
                                <div class="mt-3 flex justify-between items-center border-t border-slate-100 pt-2">
                                    <button type="button" @click="distributeEqually()" class="text-[10px] font-bold text-indigo-600 hover:underline">Dividir Igualmente</button>
                                    <p class="text-xs font-bold" :class="getSplitStatus().color" x-text="getSplitStatus().msg"></p>
                                </div>
                                <input type="hidden" name="rateio" :value="JSON.stringify(modal.split.participants)">
                            </div>
                        </div>
                    </div>

                    <!-- Category -->
                    <div>
                         <label class="text-xs font-bold text-slate-500 uppercase">Categoria</label>
                         <select x-model="modal.form.categoria_id" name="categoria_id" class="w-full p-3 border rounded-lg bg-white mt-1" required>
                            <option value="">Selecione...</option>
                            <template x-for="groupName in getGroups()">
                                <optgroup :label="groupName">
                                    <template x-for="cat in getCatsInGroup(groupName)" :key="cat.id">
                                        <option :value="cat.id" x-text="cat.nome"></option>
                                    </template>
                                </optgroup>
                            </template>
                        </select>
                    </div>
                    
                    <!-- CAMPOS EXTRAS: COMBUSTÍVEL (Visível apenas se categoria for combustível E veículo selecionado) -->
                    <div x-show="isFuel && modal.form.veiculo_id" x-transition class="bg-amber-50 p-4 rounded-xl border border-amber-100 space-y-3">
                        <div class="flex items-center gap-2 text-amber-700 text-xs font-bold uppercase mb-1">
                            <i class="fa-solid fa-gas-pump"></i> Registro de Abastecimento
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="text-[10px] font-bold text-amber-600 uppercase">Odômetro (Km)</label>
                                <input type="number" name="veiculo_km" x-model="modal.form.veiculo_km" placeholder="Ex: 50000" class="w-full p-2 border border-amber-200 rounded text-sm">
                            </div>
                            <div>
                                <label class="text-[10px] font-bold text-amber-600 uppercase">Litros</label>
                                <input type="text" name="veiculo_litros" x-model="modal.form.veiculo_litros" placeholder="Ex: 40,5" class="w-full p-2 border border-amber-200 rounded text-sm">
                            </div>
                        </div>
                    </div>

                    <!-- Recorrência -->
                    <div class="grid grid-cols-2 gap-4 pt-4 border-t border-slate-100">
                        <div>
                            <label class="text-xs font-bold text-slate-500 uppercase">Recorrência</label>
                            <select x-model="modal.form.recorrencia" name="recorrencia" class="w-full p-3 border rounded-lg bg-white mt-1">
                                <option value="UNICA">Única (Variável)</option>
                                <option value="MENSAL">Mensal (Fixo)</option>
                                <option value="ANUAL">Anual (Fixo)</option>
                                <option value="PARCELADO">Parcelado</option>
                            </select>
                        </div>
                        <div x-show="modal.form.recorrencia === 'PARCELADO'">
                            <label class="text-xs font-bold text-slate-500 uppercase">Qtd. Parcelas</label>
                            <input x-model="modal.form.qtd_parcelas" :disabled="modal.form.recorrencia !== 'PARCELADO'" :required="modal.form.recorrencia === 'PARCELADO'" type="number" name="qtd_parcelas" min="2" max="60" class="w-full p-3 border rounded-lg mt-1" placeholder="Ex: 12">
                        </div>
                    </div>
                    
                    <div>
                         <label class="text-xs font-bold text-slate-500 uppercase" x-text="modal.form.tipo === 'RECEITA' ? 'Conta de Destino (Onde caiu?)' : 'Forma de Pagamento'"></label>
                         
                         <div class="flex gap-2 mt-1">
                             <!-- Cartão (Apenas Despesa) -->
                             <div class="flex-1" x-show="modal.form.tipo === 'DESPESA'">
                                 <select x-model="modal.form.cartao_id" name="cartao_id" class="w-full p-3 border rounded-lg bg-white">
                                    <option value="">Cartão de Crédito...</option>
                                    <?php foreach($cards as $c): ?>
                                       <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nome_cartao']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                             </div>
                             
                             <!-- Conta (Despesa: Se não for cartão | Receita: Sempre) -->
                             <div class="flex-1" x-show="modal.form.tipo === 'RECEITA' || !modal.form.cartao_id">
                                 <select x-model="modal.form.conta_id" name="conta_id" class="w-full p-3 border rounded-lg bg-white">
                                    <option value="">Carteira / Dinheiro (Não afeta Banco)</option>
                                    <?php foreach($accounts as $acc): ?>
                                       <option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['nome_banco']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                             </div>
                         </div>
                         
                         <!-- Feedback Visual Cartão (Apenas Despesa) -->
                         <div x-show="modal.form.tipo === 'DESPESA' && modal.form.cartao_id" class="mt-2 p-3 bg-amber-50 border border-amber-100 rounded-lg text-xs text-amber-800 flex items-start gap-2">
                             <i class="fa-solid fa-calendar-days mt-0.5"></i>
                             <div>
                                 <strong>Compra no Crédito:</strong> A data será ajustada automaticamente para o vencimento da fatura.
                             </div>
                         </div>
                         
                         <!-- Feedback Visual Carteira/Null (Se não selecionou conta) -->
                         <div x-show="modal.form.tipo === 'RECEITA' && !modal.form.conta_id" class="mt-2 p-3 bg-indigo-50 border border-indigo-100 rounded-lg text-xs text-indigo-800 flex items-start gap-2 animate-pulse">
                             <i class="fa-solid fa-wallet mt-0.5"></i>
                             <div>
                                 <strong>Atenção:</strong> Sem selecionar uma conta bancária, o valor será registrado como "Dinheiro em Mãos" e <u>não atualizará o Saldo Líquido</u> do Dashboard.
                             </div>
                         </div>
                    </div>
                    
                    <!-- VISIBILIDADE / COMPARTILHAMENTO -->
                    <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between" :class="modal.split.active ? 'opacity-50 pointer-events-none' : ''">
                        <div>
                            <label class="text-xs font-bold text-slate-700 uppercase flex items-center gap-2">
                                <i class="fa-solid fa-eye"></i> Visibilidade na Família
                            </label>
                            <p class="text-[10px] text-slate-500 mt-0.5" x-text="modal.split.active ? 'Obrigatório para despesas divididas.' : 'Se marcado, todos verão este lançamento.'"></p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="is_shared" value="1" class="sr-only peer" x-model="modal.form.is_shared" :disabled="modal.split.active">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>

                    <!-- Comments -->
                    <div x-show="modal.isEdit" class="pt-6 border-t mt-4">
                        <h3 class="font-bold text-sm mb-3 text-slate-700">Comentários</h3>
                        <div x-html="modal.commentsHtml" class="space-y-3 max-h-40 overflow-y-auto mb-3 bg-slate-50 p-3 rounded-lg border border-slate-100 text-sm"></div>
                        <div class="flex gap-2">
                            <input type="text" x-model="modal.newComment" @keydown.enter.prevent="addComment()" class="w-full p-2 border rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Escreva um comentário...">
                            <button type="button" @click="addComment()" class="px-4 bg-indigo-600 text-white rounded-lg font-bold text-xs hover:bg-indigo-700">ENVIAR</button>
                        </div>
                    </div>
                </div>
                <div class="p-6 border-t bg-slate-50">
                    <button type="submit" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold shadow-lg shadow-indigo-600/20 hover:bg-indigo-700 transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed" :disabled="modal.split.active && getSplitStatus().status !== 'ok'">SALVAR LANÇAMENTO</button>
                </div>
            </form>
        </div>
    </div>
</div>
