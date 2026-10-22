<?php
// Não renderiza nada se estivermos na página de onboarding
if ($currentPage === 'onboarding') {
    return;
}

// --- LÓGICA DE CONFIGURAÇÃO DO APP ---
// Usa helpers centralizados (fonte única de defaults de módulos).
$appConfig = json_decode($CURRENT_USER['app_config'] ?? '{}', true);
$activeModules = active_modules($CURRENT_USER['app_config'] ?? null);
$appStyle = (is_array($appConfig) && isset($appConfig['style'])) ? $appConfig['style'] : ['color' => 'indigo', 'icon' => 'fa-crown'];

// --- MAPA DE CORES ---
// Define classes CSS baseadas na escolha do usuário
$colorMap = [
    'indigo' => ['bg_icon' => 'bg-indigo-600', 'text_sub' => 'text-indigo-400', 'shadow' => 'shadow-indigo-500/30', 'active_bg' => 'bg-indigo-600/10', 'active_text' => 'text-indigo-400', 'active_border' => 'border-indigo-600/20', 'active_shadow' => 'shadow-[0_0_15px_-3px_rgba(79,70,229,0.2)]'],
    'emerald' => ['bg_icon' => 'bg-emerald-600', 'text_sub' => 'text-emerald-400', 'shadow' => 'shadow-emerald-500/30', 'active_bg' => 'bg-emerald-600/10', 'active_text' => 'text-emerald-400', 'active_border' => 'border-emerald-600/20', 'active_shadow' => 'shadow-[0_0_15px_-3px_rgba(16,185,129,0.2)]'],
    'rose' => ['bg_icon' => 'bg-rose-600', 'text_sub' => 'text-rose-400', 'shadow' => 'shadow-rose-500/30', 'active_bg' => 'bg-rose-600/10', 'active_text' => 'text-rose-400', 'active_border' => 'border-rose-600/20', 'active_shadow' => 'shadow-[0_0_15px_-3px_rgba(225,29,72,0.2)]'],
    'amber' => ['bg_icon' => 'bg-amber-500', 'text_sub' => 'text-amber-400', 'shadow' => 'shadow-amber-500/30', 'active_bg' => 'bg-amber-500/10', 'active_text' => 'text-amber-400', 'active_border' => 'border-amber-500/20', 'active_shadow' => 'shadow-[0_0_15px_-3px_rgba(245,158,11,0.2)]'],
    'sky' => ['bg_icon' => 'bg-sky-600', 'text_sub' => 'text-sky-400', 'shadow' => 'shadow-sky-500/30', 'active_bg' => 'bg-sky-600/10', 'active_text' => 'text-sky-400', 'active_border' => 'border-sky-600/20', 'active_shadow' => 'shadow-[0_0_15px_-3px_rgba(2,132,199,0.2)]'],
    'slate' => ['bg_icon' => 'bg-slate-600', 'text_sub' => 'text-slate-400', 'shadow' => 'shadow-slate-500/30', 'active_bg' => 'bg-slate-700', 'active_text' => 'text-slate-200', 'active_border' => 'border-slate-500/50', 'active_shadow' => 'shadow-none'],
];
$theme = $colorMap[$appStyle['color']] ?? $colorMap['indigo'];

// Lógica para definir os itens do menu
if ($IS_SUPER_ADMIN) {
     $menuItems = [
        ['id' => 'superadmin', 'icon' => 'fa-shield-halved', 'label' => 'Visão Geral'],
     ];
} else {
    $menuItems = [
        ['id' => 'dashboard', 'icon' => 'fa-house', 'label' => _t('sidebar_dashboard')],
        ['id' => 'transactions', 'icon' => 'fa-list-check', 'label' => _t('sidebar_transactions')],
        ['id' => 'market', 'icon' => 'fa-basket-shopping', 'label' => 'Mercado', 'visible' => $activeModules['market'] ?? true], // Novo
        ['id' => 'assets', 'icon' => 'fa-building-columns', 'label' => _t('sidebar_assets')],
        // Módulos Condicionais
        ['id' => 'subscriptions', 'icon' => 'fa-calendar-check', 'label' => 'Assinaturas', 'visible' => $activeModules['subscriptions']],
        ['id' => 'budget', 'icon' => 'fa-chart-pie', 'label' => _t('sidebar_budget'), 'visible' => $activeModules['budget']],
        ['id' => 'goals', 'icon' => 'fa-bullseye', 'label' => _t('sidebar_goals'), 'visible' => $activeModules['goals']],
        ['id' => 'reports', 'icon' => 'fa-chart-line', 'label' => _t('sidebar_reports'), 'visible' => $activeModules['reports']],
        ['id' => 'pets', 'icon' => 'fa-paw', 'label' => 'Pets', 'visible' => $activeModules['pets']],
        ['id' => 'gamification', 'icon' => 'fa-trophy', 'label' => _t('sidebar_gamification'), 'visible' => $activeModules['gamification']],
        ['id' => 'import', 'icon' => 'fa-file-import', 'label' => _t('sidebar_import')],
    ];
}
?>

<!-- Mobile Overlay (Z-Index 40, abaixo da sidebar, acima do conteudo) -->
<div x-show="sidebarOpen" @click="sidebarOpen = false" x-transition.opacity class="fixed inset-0 bg-slate-900/60 z-40 lg:hidden backdrop-blur-sm"></div>

<!-- Sidebar (Z-Index 50, acima de tudo) -->
<aside class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 text-slate-300 flex flex-col shadow-2xl border-r border-slate-800 transform transition-transform duration-300 ease-in-out lg:translate-x-0"
       :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
    
    <div class="p-6 flex items-center justify-between h-20 border-b border-slate-800/50">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 <?php echo $theme['bg_icon']; ?> rounded-xl flex items-center justify-center text-white font-bold text-xl shadow-lg <?php echo $theme['shadow']; ?>">
                <i class="fa-solid <?php echo $appStyle['icon']; ?> text-sm"></i>
            </div>
            <div><h2 class="text-xl font-bold text-white tracking-tight">Nobl$</h2><span class="text-[10px] uppercase tracking-widest <?php echo $theme['text_sub']; ?> font-bold">Dynasty</span></div>
        </div>
        <!-- Close Button (Mobile Only) -->
        <button @click="sidebarOpen = false" class="lg:hidden text-slate-500 hover:text-white">
            <i class="fa-solid fa-xmark text-2xl"></i>
        </button>
    </div>

    <nav class="flex-1 px-4 py-6 space-y-1 overflow-y-auto custom-scrollbar">
        <?php foreach ($menuItems as $item): 
            // Pula item se estiver desabilitado na config
            if (isset($item['visible']) && !$item['visible']) continue;

            $isActive = $currentPage === $item['id'];
            $baseClasses = 'w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group';
            
            // Classes dinâmicas baseadas no tema
            $activeClasses = "{$theme['active_bg']} {$theme['active_text']} border {$theme['active_border']} {$theme['active_shadow']}";
            
            $inactiveClasses = 'hover:bg-slate-800 hover:text-white';
            $iconActiveClasses = $theme['active_text'];
            $iconInactiveClasses = 'text-slate-500 group-hover:text-slate-300';
        ?>
            <a href="index.php?page=<?php echo $item['id']; ?>" class="<?php echo $baseClasses; ?> <?php echo $isActive ? $activeClasses : $inactiveClasses; ?>">
                <i class="fa-solid <?php echo $item['icon']; ?> w-5 text-center <?php echo $isActive ? $iconActiveClasses : $iconInactiveClasses; ?>"></i>
                <span class="font-medium text-sm"><?php echo htmlspecialchars($item['label']); ?></span>
            </a>
        <?php endforeach; ?>
        
        <hr class="border-slate-800 my-4">
        
        <?php if (!$IS_SUPER_ADMIN): ?>
        <a href="index.php?page=family" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group hover:bg-slate-800 hover:text-white <?php echo ($currentPage === 'family') ? 'bg-slate-800 text-white' : ''; ?>"><i class="fa-solid fa-users w-5 text-center text-slate-500 group-hover:text-slate-300"></i><span class="font-medium text-sm">Minha Família</span></a>
        
        <?php if ($CURRENT_USER['tipo_perfil'] === 'GUARDIÃO'): ?>
        <a href="index.php?page=settings" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group hover:bg-slate-800 hover:text-white <?php echo ($currentPage === 'settings') ? 'bg-slate-800 text-white' : ''; ?>"><i class="fa-solid fa-sliders w-5 text-center text-slate-500 group-hover:text-slate-300"></i><span class="font-medium text-sm">Configurações</span></a>
        <?php endif; ?>
        
        <a href="index.php?page=profile" class="w-full flex items-center gap-3 px-4 py-3 rounded-xl transition-all duration-200 group hover:bg-slate-800 hover:text-white <?php echo ($currentPage === 'profile') ? 'bg-slate-800 text-white' : ''; ?>"><i class="fa-solid fa-user-gear w-5 text-center text-slate-500 group-hover:text-slate-300"></i><span class="font-medium text-sm">Meu Perfil</span></a>
        <?php endif; ?>
    </nav>

    <div class="p-6 border-t border-slate-800 bg-slate-950/50">
        <div class="flex items-center gap-3 mb-4">
            <img src="<?php echo htmlspecialchars($CURRENT_USER['foto_perfil']); ?>" class="w-10 h-10 rounded-full border-2 border-slate-700 bg-slate-800 object-cover" alt="Profile"/>
            <div class="overflow-hidden"><p class="text-sm font-bold text-white truncate"><?php echo htmlspecialchars($CURRENT_USER['nome']); ?></p><p class="text-[10px] uppercase text-slate-500 font-bold"><?php echo htmlspecialchars($CURRENT_USER['tipo_perfil']); ?></p></div>
        </div>
        <a href="logout.php" class="w-full text-xs py-2 bg-slate-800 rounded-lg hover:bg-slate-700 transition-colors flex items-center justify-center gap-2"><i class="fa-solid fa-right-from-bracket"></i> Sair do App</a>
    </div>
</aside>