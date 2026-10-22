
<?php
// Busca dados REAIS do banco de dados
$totalFamilies = $pdo->query("SELECT COUNT(*) FROM familias WHERE deleted_at IS NULL")->fetchColumn();
$premiumCount = $pdo->query("SELECT COUNT(*) FROM familias WHERE plano = 'PREMIUM' AND deleted_at IS NULL")->fetchColumn();
$freeCount = $totalFamilies - $premiumCount;

$totalUsers = $pdo->query("SELECT COUNT(*) FROM usuarios WHERE deleted_at IS NULL")->fetchColumn();

// Busca os últimos 5 Tenants (famílias)
$latestTenants = $pdo->query("SELECT nome_familia, plano, status, created_at FROM familias WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 5")->fetchAll();

$mrrData = [
  'labels' => ['Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out'],
  'datasets' => [[
      'label' => 'MRR',
      'data' => [12000, 18000, 25000, 32000, 45000, 58000],
      'backgroundColor' => '#4f46e5',
      'borderRadius' => 6,
      'barPercentage' => 0.6,
  ]]
];
?>
<div class="space-y-6">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-slate-900 text-white p-6 rounded-2xl border border-slate-800">
            <p class="text-slate-400 text-xs font-bold uppercase tracking-wider mb-2">MRR (Estimado)</p>
            <h3 class="text-3xl font-black">R$ 58.200</h3>
            <p class="text-emerald-400 text-xs font-bold mt-2"><i class="fa-solid fa-arrow-up"></i> +14.5% este mês</p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Total Famílias</p>
            <h3 class="text-3xl font-black text-slate-900"><?php echo $totalFamilies; ?></h3>
            <p class="text-slate-400 text-xs font-medium mt-2"><?php echo $premiumCount; ?> Premium • <?php echo $freeCount; ?> Free</p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Churn Rate</p>
            <h3 class="text-3xl font-black text-slate-900">1.2%</h3>
            <p class="text-emerald-500 text-xs font-bold mt-2">Dentro da meta</p>
        </div>
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <p class="text-slate-500 text-xs font-bold uppercase tracking-wider mb-2">Usuários Ativos</p>
            <h3 class="text-3xl font-black text-slate-900"><?php echo $totalUsers; ?></h3>
            <div class="flex -space-x-2 mt-4">
                <?php for($i = 1; $i <= min($totalUsers, 5); $i++): ?>
                <img src="https://picsum.photos/id/<?php echo $i+10; ?>/40/40" class="w-6 h-6 rounded-full border-2 border-white" />
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
            <h3 class="font-bold text-slate-800 mb-6">Crescimento de Receita (MRR)</h3>
            <div class="h-[250px]"><canvas id="mrrChart"></canvas></div>
        </div>

        <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
            <h3 class="font-bold text-slate-800 mb-6">Últimos Tenants (Famílias)</h3>
            <div class="space-y-4">
            <?php foreach($latestTenants as $tenant): ?>
              <div class="flex items-center justify-between p-3 rounded-xl bg-slate-50 border border-slate-100">
                <div>
                  <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($tenant['nome_familia']); ?></p>
                  <p class="text-[10px] text-slate-400 font-bold uppercase"><?php echo $tenant['plano']; ?> • <?php echo date('d/m/Y', strtotime($tenant['created_at'])); ?></p>
                </div>
                <span class="text-[10px] font-bold px-2 py-1 rounded uppercase <?php echo $tenant['status'] === 'ATIVO' ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600'; ?>">
                    <?php echo $tenant['status']; ?>
                </span>
              </div>
            <?php endforeach; ?>
            <?php if(empty($latestTenants)): ?>
                <p class="text-slate-400 text-center py-4">Nenhuma família registrada.</p>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const ctxMrr = document.getElementById('mrrChart');
    if (ctxMrr) {
        new Chart(ctxMrr, {
            type: 'bar',
            data: <?php echo json_encode($mrrData); ?>,
            options: {
                responsive: true, maintainAspectRatio: false,
                scales: { 
                  y: { grid: { display: false } }, 
                  x: { grid: { display: false } }
                },
                plugins: { legend: { display: false } }
            }
        });
    }
});
</script>
