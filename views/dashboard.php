<?php
// --- BUSCAS DE DADOS INICIAIS ---
$month = $_GET['month'] ?? date('n');
$year = $_GET['year'] ?? date('Y');
$filter_user_id = $_GET['user_id'] ?? '';

// Busca usuários da família para o filtro
$stmtUsers = $pdo->prepare("SELECT id, nome FROM usuarios WHERE familia_id = ? AND deleted_at IS NULL ORDER BY nome");
$stmtUsers->execute([$LOGGED_IN_FAMILY_ID]);
$familyUsers = $stmtUsers->fetchAll();

// Metas
$stmt = $pdo->prepare("SELECT nome, valor_atual, valor_alvo FROM metas WHERE familia_id = ? AND deleted_at IS NULL ORDER BY data_limite ASC LIMIT 3");
$stmt->execute([$LOGGED_IN_FAMILY_ID]);
$goals = $stmt->fetchAll();

// --- LÓGICA DE PRIVACIDADE ---
$privacyCondition = " AND (
    l.usuario_id = $LOGGED_IN_USER_ID 
    OR u.is_restricted = 0 
    OR l.is_shared = 1
    OR l.veiculo_id IS NOT NULL 
    OR l.residencia_id IS NOT NULL
)";

$userSqlL = $filter_user_id ? " AND l.usuario_id = ? " : $privacyCondition;
$userSqlU = $filter_user_id ? " AND u.id = ? " : " AND (u.id = $LOGGED_IN_USER_ID OR u.is_restricted = 0)";
$userParam = $filter_user_id ? [$filter_user_id] : [];

// Atividade Recente (Ignora Transferências Internas P2P)
$sqlActivity = "SELECT l.tipo, l.descricao, l.valor, l.is_shared, l.created_at, u.nome as usuario_nome, u.foto_perfil, c.nome as categoria_nome, c.icone as categoria_icone 
    FROM lancamentos l 
    JOIN usuarios u ON l.usuario_id = u.id 
    LEFT JOIN categorias c ON l.categoria_id = c.id
    WHERE l.familia_id = ? 
    AND l.deleted_at IS NULL 
    AND l.is_internal_transfer = 0 
    $userSqlL 
    ORDER BY l.created_at DESC LIMIT 8";
$stmt = $pdo->prepare($sqlActivity);
$stmt->execute(array_merge([$LOGGED_IN_FAMILY_ID], $userParam));
$recentActivity = $stmt->fetchAll();

// --- CONSULTAS GRÁFICOS ---
// 1. Radar de Vencimentos
$sqlRadar = "SELECT l.descricao, l.valor, l.data_competencia, c.nome as categoria_nome, c.icone 
    FROM lancamentos l 
    LEFT JOIN categorias c ON l.categoria_id = c.id 
    JOIN usuarios u ON l.usuario_id = u.id
    WHERE l.familia_id = ? 
    AND l.tipo = 'DESPESA' 
    AND l.status = 'PENDENTE' 
    AND l.cartao_id IS NULL
    AND l.is_internal_transfer = 0
    AND l.data_competencia BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) 
    AND l.deleted_at IS NULL $userSqlL 
    ORDER BY l.data_competencia ASC LIMIT 5";
$stmtRadar = $pdo->prepare($sqlRadar);
$stmtRadar->execute(array_merge([$LOGGED_IN_FAMILY_ID], $userParam));
$upcomingBills = $stmtRadar->fetchAll();

// 2. Ranking de Economia
$sqlRanking = "SELECT u.nome, u.foto_perfil, 
    COALESCE(SUM(l.valor), 0) as total_gasto 
    FROM usuarios u 
    LEFT JOIN lancamentos l ON u.id = l.usuario_id AND l.tipo = 'DESPESA' AND l.is_internal_transfer = 0 AND MONTH(l.data_competencia) = ? AND YEAR(l.data_competencia) = ? AND l.deleted_at IS NULL 
    WHERE u.familia_id = ? AND u.deleted_at IS NULL $userSqlU 
    GROUP BY u.id 
    ORDER BY total_gasto ASC LIMIT 5";
$stmtRanking = $pdo->prepare($sqlRanking);
$stmtRanking->execute(array_merge([$month, $year, $LOGGED_IN_FAMILY_ID], $userParam));
$economyRanking = $stmtRanking->fetchAll();

// 3. Alerta de Orçamento
$joinCondition = $filter_user_id ? "AND l.usuario_id = ?" : $privacyCondition;
$checkCol = $pdo->query("SHOW COLUMNS FROM orcamentos LIKE 'ativo'")->fetch();
$activeFilter = $checkCol ? "AND o.ativo = 1" : "";

$sqlAlerts = "SELECT c.nome, o.valor_orcado, COALESCE(SUM(l.valor), 0) as valor_gasto 
    FROM orcamentos o 
    JOIN categorias c ON o.categoria_id = c.id 
    LEFT JOIN lancamentos l JOIN usuarios u ON l.usuario_id = u.id ON l.categoria_id = c.id AND MONTH(l.data_competencia) = o.mes AND YEAR(l.data_competencia) = o.ano AND l.deleted_at IS NULL AND l.is_internal_transfer = 0 $joinCondition
    WHERE o.familia_id = ? AND o.mes = ? AND o.ano = ? $activeFilter
    GROUP BY c.id, c.nome, o.valor_orcado
    HAVING COALESCE(SUM(l.valor), 0) >= (o.valor_orcado * 0.8)
    ORDER BY (COALESCE(SUM(l.valor), 0) / GREATEST(o.valor_orcado, 1)) DESC LIMIT 3";

$paramsAlerts = [];
if ($filter_user_id) $paramsAlerts[] = $filter_user_id;
array_push($paramsAlerts, $LOGGED_IN_FAMILY_ID, $month, $year);

$stmtAlerts = $pdo->prepare($sqlAlerts);
try {
    $stmtAlerts->execute($paramsAlerts);
    $budgetAlerts = $stmtAlerts->fetchAll();
} catch (Exception $e) {
    $budgetAlerts = [];
}

// 4. Busca Contas para pagamento de Acertos
$stmtAccs = $pdo->prepare("SELECT id, nome_banco FROM contas_bancarias WHERE familia_id = ? AND usuario_id = ? AND deleted_at IS NULL");
$stmtAccs->execute([$LOGGED_IN_FAMILY_ID, $LOGGED_IN_USER_ID]);
$myAccounts = $stmtAccs->fetchAll();

$months = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
?>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('dashboardController', (initialMonth, initialYear, initialUserId) => ({
        month: initialMonth,
        year: initialYear,
        userId: initialUserId,
        kpis: { totalBalance: '...', income: '...', expense: '...', balance: '...', balanceRaw: 0 },
        categoryChart: null,
        calendar: { header: '', blanks: [], days: [] },
        settlements: { i_owe: [], to_receive: [] },
        
        init() {
            this.fetchData();
            this.fetchSettlements();
            // Atualiza ao focar na janela (para pegar mudanças de outras abas ou modais)
            window.addEventListener('focus', () => this.fetchData());
        },

        fetchData() {
            fetch(`data.php?action=get_dashboard_data&month=${this.month}&year=${this.year}&user_id=${this.userId}`)
                .then(res => res.json())
                .then(data => {
                    this.kpis = data.kpis;
                    const bal = parseFloat(data.kpis.balance.replace(/[^0-9.-]+/g,""));
                    this.kpis.balanceRaw = isNaN(bal) ? 0 : bal;
                    this.updateCategoryChart(data.categoryChart);
                });
            this.generateCalendar();
        },

        fetchSettlements() {
            fetch('data.php?action=get_pending_settlements')
                .then(res => res.json())
                .then(data => {
                    this.settlements = data;
                });
        },
        
        reloadPage() {
             window.location.search = `?page=dashboard&month=${this.month}&year=${this.year}&user_id=${this.userId}`;
        },

        updateCategoryChart(data) {
            if (this.categoryChart) {
                this.categoryChart.destroy();
            }
            const ctx = document.getElementById('categoryChart');
            if (ctx) {
                this.categoryChart = new Chart(ctx, { 
                    type: 'doughnut', 
                    data: data, 
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { 
                            legend: { position: 'right', labels: { boxWidth: 10, font: { size: 10 } } } 
                        },
                        layout: { padding: 0 }
                    } 
                });
            }
        },

        generateCalendar() {
            const monthNames = ["Janeiro", "Fevereiro", "Março", "Abril", "Maio", "Junho", "Julho", "Agosto", "Setembro", "Outubro", "Novembro", "Dezembro"];
            this.calendar.header = `${monthNames[this.month - 1]} ${this.year}`;
            const date = new Date(this.year, this.month - 1, 1);
            const daysInMonth = new Date(this.year, this.month, 0).getDate();
            const firstDayOfWeek = date.getDay();
            
            this.calendar.blanks = Array(firstDayOfWeek).fill('');
            this.calendar.days = [];
            
            fetch(`data.php?action=get_calendar_events&month=${this.month}&year=${this.year}&user_id=${this.userId}`)
                .then(res => res.json())
                .then(events => {
                    let newDays = [];
                    for (let i = 1; i <= daysInMonth; i++) {
                        const isToday = new Date().toDateString() === new Date(this.year, this.month - 1, i).toDateString();
                        const dayEvents = events[i] || [];
                        newDays.push({
                            date: i,
                            isToday: isToday,
                            events: dayEvents
                        });
                    }
                    this.calendar.days = newDays;
                });
        },
        
        changeMonth(offset) {
            let newDate = new Date(this.year, this.month - 1 + offset, 1);
            this.year = newDate.getFullYear();
            this.month = newDate.getMonth() + 1;
            this.fetchData(); 
        }
    }));
});
</script>

<div x-data="dashboardController(<?php echo $month; ?>, <?php echo $year; ?>, '<?php echo $filter_user_id; ?>')" class="space-y-6 animate-fade-in pb-12">
    
    <!-- 1. HERO SECTION -->
    <div class="bg-slate-900 rounded-3xl p-1 shadow-2xl relative overflow-hidden">
        <div class="absolute top-0 right-0 w-[500px] h-[500px] bg-indigo-600 rounded-full mix-blend-overlay filter blur-[100px] opacity-30 -mr-20 -mt-20"></div>
        <div class="absolute bottom-0 left-0 w-[300px] h-[300px] bg-emerald-600 rounded-full mix-blend-overlay filter blur-[80px] opacity-20 -ml-10 -mb-10"></div>
        
        <div class="bg-slate-900/50 backdrop-blur-xl rounded-[22px] p-6 sm:p-8 relative z-10 border border-white/5">
            <!-- Header & Filters -->
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                <div>
                    <h2 class="text-white text-xl font-bold flex items-center gap-2">
                        <i class="fa-solid fa-chart-line text-indigo-400"></i> Visão Geral
                    </h2>
                    <p class="text-slate-400 text-xs">Resumo financeiro da dinastia.</p>
                </div>
                
                <div class="flex flex-wrap gap-2 bg-slate-800/50 p-1 rounded-xl border border-white/5">
                    <select x-model="userId" @change="reloadPage()" class="bg-transparent text-white text-xs font-medium px-3 py-2 rounded-lg outline-none cursor-pointer hover:bg-white/5 border-none focus:ring-0">
                        <option value="" class="bg-slate-800 text-slate-300">Toda a Família</option>
                        <?php foreach ($familyUsers as $fu): ?>
                            <option value="<?php echo $fu['id']; ?>" class="bg-slate-800 text-slate-300"><?php echo htmlspecialchars($fu['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="w-px bg-white/10 my-1"></div>
                    <select x-model="month" @change="reloadPage()" class="bg-transparent text-white text-xs font-medium px-3 py-2 rounded-lg outline-none cursor-pointer hover:bg-white/5 border-none focus:ring-0">
                        <?php foreach ($months as $num => $name): ?>
                            <option value="<?php echo $num; ?>" class="bg-slate-800 text-slate-300"><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select x-model="year" @change="reloadPage()" class="bg-transparent text-white text-xs font-medium px-3 py-2 rounded-lg outline-none cursor-pointer hover:bg-white/5 border-none focus:ring-0">
                        <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" class="bg-slate-800 text-slate-300"><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <!-- Big Numbers -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-end">
                <div class="md:col-span-1">
                    <div class="flex items-center gap-1 mb-1">
                        <p class="text-indigo-200 text-xs font-bold uppercase tracking-widest">Saldo Líquido</p>
                        <i class="fa-solid fa-circle-info text-indigo-400/50 text-[10px]" title="Soma apenas Contas Bancárias e Investimentos. Dinheiro 'em carteira' não registrado em conta não é somado aqui."></i>
                    </div>
                    <h3 class="text-4xl lg:text-5xl font-black text-white" x-text="kpis.totalBalance">...</h3>
                    <div class="mt-2 flex items-center gap-2 text-xs">
                        <span class="px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-bold" x-show="kpis.balanceRaw > 0">Positivo</span>
                        <span class="text-slate-400">Balanço do Mês: <strong class="text-white" x-text="kpis.balance">...</strong></span>
                    </div>
                </div>
                
                <div class="md:col-span-2 grid grid-cols-2 gap-4">
                    <div class="bg-emerald-500/10 border border-emerald-500/20 rounded-2xl p-4">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/20 text-emerald-400 flex items-center justify-center"><i class="fa-solid fa-arrow-up"></i></div>
                            <p class="text-emerald-100 text-xs font-bold uppercase">Receitas</p>
                        </div>
                        <p class="text-2xl font-bold text-white" x-text="kpis.income">...</p>
                    </div>
                    <div class="bg-rose-500/10 border border-rose-500/20 rounded-2xl p-4">
                        <div class="flex items-center gap-3 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-rose-500/20 text-rose-400 flex items-center justify-center"><i class="fa-solid fa-arrow-down"></i></div>
                            <p class="text-rose-100 text-xs font-bold uppercase">Despesas</p>
                        </div>
                        <p class="text-2xl font-bold text-white" x-text="kpis.expense">...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 2. MAIN GRID -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- COLUNA PRINCIPAL (2/3) -->
        <div class="lg:col-span-2 space-y-8">
            
            <!-- WIDGET ACERTOS PENDENTES (Mostra apenas se houver) -->
            <div x-show="settlements.i_owe.length > 0 || settlements.to_receive.length > 0" 
                 x-cloak class="bg-indigo-50 border border-indigo-100 rounded-3xl p-6 shadow-sm">
                <h3 class="font-bold text-indigo-900 text-sm mb-4 flex items-center gap-2"><i class="fa-solid fa-handshake"></i> Acertos Pendentes</h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Dívidas (Eu Devo) -->
                    <template x-for="item in settlements.i_owe">
                        <div class="bg-white p-3 rounded-xl border border-indigo-100 flex justify-between items-center shadow-sm">
                            <div>
                                <p class="text-[10px] uppercase font-bold text-slate-400">Você deve a <span x-text="item.outro_usuario"></span></p>
                                <p class="font-bold text-slate-800 text-sm" x-text="item.descricao"></p>
                            </div>
                            <div class="text-right">
                                <p class="text-sm font-black text-rose-600 mb-1" x-text="'- ' + item.valor_fmt"></p>
                                <form action="index.php" method="POST" onsubmit="return confirm('Confirmar pagamento? O valor sairá da sua conta selecionada.');">
                                    <input type="hidden" name="action" value="pay_settlement">
                                    <input type="hidden" name="settlement_id" :value="item.settlement_id">
                                    <div class="flex items-center gap-1">
                                        <select name="conta_id" class="text-[10px] p-1 border rounded bg-slate-50 w-24" required>
                                            <option value="">Pagar de...</option>
                                            <?php foreach($myAccounts as $acc): ?><option value="<?php echo $acc['id']; ?>"><?php echo htmlspecialchars($acc['nome_banco']); ?></option><?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="bg-indigo-600 text-white p-1 rounded hover:bg-indigo-700"><i class="fa-solid fa-check"></i></button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </template>

                    <!-- Recebíveis (Devem pra mim) -->
                    <template x-for="item in settlements.to_receive">
                        <div class="bg-white p-3 rounded-xl border border-slate-200 flex justify-between items-center shadow-sm opacity-80">
                            <div>
                                <p class="text-[10px] uppercase font-bold text-slate-400">A receber de <span x-text="item.outro_usuario"></span></p>
                                <p class="font-bold text-slate-800 text-sm" x-text="item.descricao"></p>
                            </div>
                            <div>
                                <p class="text-sm font-black text-emerald-600" x-text="'+ ' + item.valor_fmt"></p>
                                <p class="text-[10px] text-slate-400 italic text-right">Aguardando...</p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Linha de Gráficos -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Por Categoria -->
                <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm flex flex-col">
                    <h3 class="font-bold text-slate-800 text-sm mb-4">Onde o dinheiro foi?</h3>
                    <div class="flex-1 min-h-[200px] relative">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>

                <!-- Ranking da Economia -->
                <div class="bg-gradient-to-br from-amber-50 to-orange-50 p-6 rounded-3xl border border-amber-100 shadow-sm flex flex-col">
                    <h3 class="font-bold text-amber-800 text-sm mb-4 flex items-center gap-2"><i class="fa-solid fa-trophy"></i> Ranking da Economia</h3>
                    <p class="text-[10px] text-amber-700/70 mb-2 mt-[-10px]">Quem poupou mais (menor gasto) este mês.</p>
                    <div class="space-y-4 overflow-y-auto max-h-[200px] custom-scrollbar pr-2">
                        <?php $rank = 1; foreach($economyRanking as $saver): ?>
                        <div class="flex items-center gap-3">
                            <div class="font-black text-amber-400 w-6 text-sm text-center bg-white rounded-lg py-1 shadow-sm border border-amber-100"><?php echo $rank++; ?>º</div>
                            <img src="<?php echo htmlspecialchars($saver['foto_perfil']); ?>" class="w-8 h-8 rounded-full border-2 border-white shadow-sm object-cover">
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold text-slate-800 truncate"><?php echo htmlspecialchars($saver['nome']); ?></p>
                            </div>
                            <p class="text-xs font-bold text-amber-600 bg-amber-100 px-2 py-1 rounded-lg border border-amber-200 shadow-sm">
                                -<?php echo format_currency($saver['total_gasto'], $CURRENT_USER['moeda']); ?>
                            </p>
                        </div>
                        <?php endforeach; if(empty($economyRanking)) echo '<div class="h-full flex flex-col items-center justify-center text-slate-400 text-xs mt-4">Sem dados de gastos para ranking.</div>'; ?>
                    </div>
                </div>
            </div>

            <!-- Lista de Atividade Recente Limpa -->
            <div class="bg-white rounded-3xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-6 border-b border-slate-100 flex justify-between items-center">
                    <h3 class="font-bold text-slate-800">Atividade Recente</h3>
                    <a href="index.php?page=transactions" class="text-xs font-bold text-indigo-600 hover:text-indigo-800">Ver tudo</a>
                </div>
                <div class="divide-y divide-slate-50">
                    <?php foreach ($recentActivity as $act): ?>
                    <div class="p-4 hover:bg-slate-50 transition-colors flex items-center gap-4">
                        <div class="w-10 h-10 rounded-full bg-slate-50 border border-slate-100 flex items-center justify-center text-slate-400 text-sm shrink-0">
                            <i class="fa-solid <?php echo $act['categoria_icone'] ?? 'fa-receipt'; ?>"></i>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-slate-800 truncate"><?php echo htmlspecialchars($act['descricao']); ?></p>
                            <div class="flex items-center gap-2 text-xs text-slate-500">
                                <img src="<?php echo $act['foto_perfil']; ?>" class="w-4 h-4 rounded-full" title="<?php echo $act['usuario_nome']; ?>">
                                <span><?php echo htmlspecialchars($act['usuario_nome']); ?></span>
                                <span>•</span>
                                <span><?php echo date('d/m', strtotime($act['created_at'])); ?></span>
                            </div>
                        </div>
                        <p class="font-bold text-sm <?php echo $act['tipo'] === 'RECEITA' ? 'text-emerald-600' : 'text-slate-800'; ?>">
                            <?php echo $act['tipo'] === 'RECEITA' ? '+' : '-'; ?> <?php echo format_currency($act['valor'], $CURRENT_USER['moeda']); ?>
                        </p>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($recentActivity)): ?>
                        <div class="text-center py-12 text-slate-400">
                            <i class="fa-regular fa-clock text-3xl mb-2"></i>
                            <p class="text-xs">Nenhuma atividade recente.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- COLUNA LATERAL (1/3) -->
        <div class="space-y-6">
            
            <!-- Calendário Compacto -->
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-slate-800 text-sm" x-text="calendar.header"></h3>
                    <div class="flex gap-1">
                        <button @click="changeMonth(-1)" class="w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100 text-slate-400"><i class="fa-solid fa-chevron-left text-xs"></i></button>
                        <button @click="changeMonth(1)" class="w-6 h-6 flex items-center justify-center rounded hover:bg-slate-100 text-slate-400"><i class="fa-solid fa-chevron-right text-xs"></i></button>
                    </div>
                </div>
                <div class="grid grid-cols-7 gap-1 text-center text-[10px] mb-2">
                    <template x-for="day in ['D', 'S', 'T', 'Q', 'Q', 'S', 'S']"><div class="font-bold text-slate-400" x-text="day"></div></template>
                </div>
                <div class="grid grid-cols-7 gap-1 text-center text-xs">
                    <template x-for="blank in calendar.blanks"><div class="h-8"></div></template>
                    <template x-for="(day, index) in calendar.days" :key="index">
                        <div class="h-8 flex items-center justify-center rounded-lg relative cursor-default transition-colors group"
                             :class="{'bg-slate-900 text-white font-bold': day.isToday, 'hover:bg-slate-50': !day.isToday}">
                             <span x-text="day.date"></span>
                             
                             <!-- Ponto Indicador -->
                             <div x-show="day.events && day.events.length > 0" class="absolute bottom-1 w-1 h-1 rounded-full bg-indigo-500"></div>

                             <!-- Tooltip Popover (Exibe EM CIMA) -->
                             <div x-show="day.events && day.events.length > 0" 
                                  class="absolute bottom-full mb-2 left-1/2 -translate-x-1/2 z-50 w-48 bg-white rounded-xl shadow-xl border border-slate-200 p-3 hidden group-hover:block animate-fade-in pointer-events-none transform origin-bottom">
                                  <div class="text-[10px] font-bold text-slate-400 uppercase border-b border-slate-100 pb-1 mb-1 text-left" x-text="day.date + ' ' + calendar.header"></div>
                                  <div class="space-y-1">
                                      <template x-for="ev in day.events">
                                          <div class="flex justify-between items-center text-[10px]">
                                              <span class="truncate max-w-[70%] font-medium" :class="ev.color" x-text="ev.descricao"></span>
                                              <span class="font-bold" :class="ev.color" x-text="ev.valor"></span>
                                          </div>
                                      </template>
                                  </div>
                                  <div class="absolute top-full left-1/2 -translate-x-1/2 -mt-1 w-2 h-2 bg-white border-b border-r border-slate-200 rotate-45"></div>
                             </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Radar de Vencimento -->
            <div class="bg-indigo-900 text-white p-6 rounded-3xl shadow-lg relative overflow-hidden">
                <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-500 rounded-full mix-blend-overlay filter blur-3xl opacity-20"></div>
                
                <h3 class="font-bold text-sm mb-4 flex items-center gap-2 relative z-10"><i class="fa-solid fa-radar text-emerald-400"></i> Radar da Semana</h3>
                
                <div class="space-y-3 relative z-10">
                    <?php if(empty($upcomingBills)): ?>
                        <div class="text-center py-6 text-indigo-300 text-xs">
                            <i class="fa-solid fa-mug-hot text-2xl mb-2 block opacity-50"></i>
                            Tudo tranquilo!<br>Nenhuma conta vencendo em 7 dias.
                        </div>
                    <?php else: ?>
                        <?php foreach($upcomingBills as $bill): 
                            $today = new DateTime();
                            $dueDate = new DateTime($bill['data_competencia']);
                            $interval = $today->diff($dueDate);
                            $diff = (int)$interval->format('%r%a');
                            
                            $isToday = $diff === 0;
                            $isOverdue = $diff < 0;
                            
                            if ($isToday) $statusText = 'Vence Hoje';
                            elseif ($isOverdue) $statusText = 'Venceu';
                            elseif ($diff == 1) $statusText = 'Amanhã';
                            else $statusText = "Em $diff dias";
                            
                            $statusColor = ($isToday || $isOverdue) ? 'text-rose-300 font-bold animate-pulse' : 'text-indigo-300';
                        ?>
                        <div class="flex items-center gap-3 p-2 rounded-xl hover:bg-white/10 transition-colors border border-transparent hover:border-white/5">
                            <div class="w-8 h-8 rounded-lg bg-white/10 flex items-center justify-center text-xs">
                                <i class="fa-solid <?php echo $bill['icone'] ?? 'fa-file-invoice'; ?>"></i>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs font-bold truncate"><?php echo htmlspecialchars($bill['descricao']); ?></p>
                                <p class="text-[10px] <?php echo $statusColor; ?>"><?php echo $statusText; ?> • <?php echo date('d/m', strtotime($bill['data_competencia'])); ?></p>
                            </div>
                            <p class="text-xs font-bold"><?php echo format_currency($bill['valor'], $CURRENT_USER['moeda']); ?></p>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Alertas & Planejamento -->
            <div class="bg-white p-6 rounded-3xl border border-slate-200 shadow-sm space-y-6">
                <!-- Orçamento Crítico -->
                <?php if(!empty($budgetAlerts)): ?>
                <div>
                    <h3 class="font-bold text-rose-600 text-sm mb-3 flex items-center gap-2"><i class="fa-solid fa-triangle-exclamation"></i> Orçamento Crítico</h3>
                    <div class="space-y-3">
                        <?php foreach($budgetAlerts as $alert): 
                            $divisor = $alert['valor_orcado'] > 0 ? $alert['valor_orcado'] : 1;
                            $pct = ($alert['valor_gasto'] / $divisor) * 100;
                        ?>
                        <div>
                            <div class="flex justify-between text-xs mb-1 font-medium text-slate-600">
                                <span><?php echo htmlspecialchars($alert['nome']); ?></span>
                                <span class="text-rose-600"><?php echo round($pct); ?>%</span>
                            </div>
                            <div class="w-full bg-slate-100 rounded-full h-1.5"><div class="bg-rose-500 h-1.5 rounded-full" style="width: <?php echo min($pct, 100); ?>%"></div></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Metas -->
                <div>
                    <h3 class="font-bold text-slate-800 text-sm mb-3 flex items-center gap-2"><i class="fa-solid fa-bullseye text-indigo-500"></i> Metas Principais</h3>
                    <div class="space-y-4">
                        <?php foreach ($goals as $goal): $progress = $goal['valor_alvo'] > 0 ? ($goal['valor_atual'] / $goal['valor_alvo']) * 100 : 0; ?>
                        <div class="bg-slate-50 p-3 rounded-xl border border-slate-100">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($goal['nome']); ?></span>
                                <span class="text-[10px] font-bold text-indigo-600 bg-indigo-100 px-1.5 py-0.5 rounded"><?php echo round($progress); ?>%</span>
                            </div>
                            <div class="w-full bg-slate-200 rounded-full h-1.5"><div class="bg-indigo-500 h-1.5 rounded-full" style="width: <?php echo $progress; ?>%"></div></div>
                        </div>
                        <?php endforeach; if(empty($goals)) echo '<p class="text-xs text-slate-400">Nenhuma meta ativa.</p>'; ?>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>