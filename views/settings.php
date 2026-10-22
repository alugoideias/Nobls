<?php
if ($CURRENT_USER['tipo_perfil'] !== 'GUARDIÃO' && !$IS_SUPER_ADMIN) {
    echo '<div class="flex flex-col items-center justify-center h-64 text-slate-400"><i class="fa-solid fa-lock text-4xl mb-4"></i><p>Apenas Guardiões podem acessar as configurações da dinastia.</p></div>';
    return;
}

// Decodifica configs atuais usando os defaults centralizados (fonte única).
$appConfig = json_decode($CURRENT_USER['app_config'] ?? '{}', true);
$modules = active_modules($CURRENT_USER['app_config'] ?? null);
$style = (is_array($appConfig) && isset($appConfig['style'])) ? $appConfig['style'] : ['color' => 'indigo', 'icon' => 'fa-crown'];

// Opções de ícones
$icons = ['fa-crown', 'fa-shield-halved', 'fa-house-chimney', 'fa-heart', 'fa-star', 'fa-leaf', 'fa-bolt', 'fa-gem', 'fa-paw', 'fa-dragon'];

// Opções de Cores (Nome => Classe Tailwind Base)
$colors = [
    'indigo' => ['bg' => 'bg-indigo-600', 'text' => 'text-indigo-600', 'border' => 'border-indigo-600', 'ring' => 'ring-indigo-500'],
    'emerald' => ['bg' => 'bg-emerald-600', 'text' => 'text-emerald-600', 'border' => 'border-emerald-600', 'ring' => 'ring-emerald-500'],
    'rose' => ['bg' => 'bg-rose-600', 'text' => 'text-rose-600', 'border' => 'border-rose-600', 'ring' => 'ring-rose-500'],
    'amber' => ['bg' => 'bg-amber-500', 'text' => 'text-amber-500', 'border' => 'border-amber-500', 'ring' => 'ring-amber-500'],
    'sky' => ['bg' => 'bg-sky-600', 'text' => 'text-sky-600', 'border' => 'border-sky-600', 'ring' => 'ring-sky-500'],
    'slate' => ['bg' => 'bg-slate-700', 'text' => 'text-slate-700', 'border' => 'border-slate-700', 'ring' => 'ring-slate-500'],
];
?>

<div class="max-w-4xl mx-auto space-y-8 animate-fade-in" x-data="{ color: '<?php echo $style['color']; ?>', icon: '<?php echo $style['icon']; ?>' }">
    <div class="flex items-center justify-between">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Configurações da Dinastia</h2>
            <p class="text-sm text-slate-500">Personalize a experiência para sua família.</p>
        </div>
        <button type="submit" form="settings-form" class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 hover:bg-indigo-700 transition-all flex items-center gap-2 active:scale-95">
            <i class="fa-solid fa-floppy-disk"></i> SALVAR TUDO
        </button>
    </div>

    <form id="settings-form" action="index.php" method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_app_settings">
        <input type="hidden" name="theme_color" :value="color">
        <input type="hidden" name="family_icon" :value="icon">

        <!-- Módulos -->
        <div class="bg-white p-8 rounded-2xl border border-slate-200 shadow-sm">
            <h3 class="font-bold text-lg text-slate-800 mb-6 flex items-center gap-2"><i class="fa-solid fa-layer-group text-slate-400"></i> Módulos Ativos</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <!-- Toggle Item -->
                <?php
                $modList = [
                    ['key' => 'mod_market', 'label' => 'Gestão de Mercado', 'desc' => 'Controle de despensa e reposição inteligente.', 'checked' => $modules['market'] ?? true, 'icon' => 'fa-basket-shopping'],
                    ['key' => 'mod_budget', 'label' => 'Orçamento', 'desc' => 'Metas de gastos mensais por categoria.', 'checked' => $modules['budget'], 'icon' => 'fa-chart-pie'],
                    ['key' => 'mod_goals', 'label' => 'Metas & Sonhos', 'desc' => 'Progresso para objetivos financeiros.', 'checked' => $modules['goals'], 'icon' => 'fa-bullseye'],
                    ['key' => 'mod_pets', 'label' => 'Pets', 'desc' => 'Gestão de saúde e custos dos animais.', 'checked' => $modules['pets'], 'icon' => 'fa-paw'],
                    ['key' => 'mod_subscriptions', 'label' => 'Assinaturas', 'desc' => 'Controle de recorrentes (Netflix, etc).', 'checked' => $modules['subscriptions'], 'icon' => 'fa-calendar-check'],
                    ['key' => 'mod_reports', 'label' => 'Relatórios (Ise)', 'desc' => 'IA analítica e gráficos detalhados.', 'checked' => $modules['reports'], 'icon' => 'fa-chart-line'],
                    ['key' => 'mod_gamification', 'label' => 'Jornada & XP', 'desc' => 'Sistema de níveis e conquistas.', 'checked' => $modules['gamification'], 'icon' => 'fa-trophy'],
                ];
                foreach($modList as $mod): ?>
                <label class="flex items-start gap-4 p-4 rounded-xl border border-slate-100 hover:border-indigo-100 hover:bg-slate-50 cursor-pointer transition-all group relative">
                    <div class="w-10 h-10 rounded-lg bg-white border border-slate-100 flex items-center justify-center text-slate-400 shadow-sm group-hover:text-indigo-500 transition-colors">
                        <i class="fa-solid <?php echo $mod['icon']; ?>"></i>
                    </div>
                    <div class="flex-1">
                        <div class="flex justify-between items-center mb-1">
                            <span class="font-bold text-slate-700 text-sm"><?php echo $mod['label']; ?></span>
                            <div class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="<?php echo $mod['key']; ?>" class="sr-only peer" <?php echo $mod['checked'] ? 'checked' : ''; ?>>
                                <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-indigo-600"></div>
                            </div>
                        </div>
                        <p class="text-xs text-slate-400 leading-tight"><?php echo $mod['desc']; ?></p>
                    </div>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Aparência -->
        <div class="bg-white p-8 rounded-2xl border border-slate-200 shadow-sm">
            <h3 class="font-bold text-lg text-slate-800 mb-6 flex items-center gap-2"><i class="fa-solid fa-paintbrush text-slate-400"></i> Aparência & Marca</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Cores -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-3">Cor do Tema</label>
                    <div class="flex flex-wrap gap-3">
                        <?php foreach($colors as $key => $classes): ?>
                        <button type="button" @click="color = '<?php echo $key; ?>'" 
                                class="w-10 h-10 rounded-full cursor-pointer transition-transform hover:scale-110 focus:outline-none flex items-center justify-center shadow-sm <?php echo $classes['bg']; ?>"
                                :class="color === '<?php echo $key; ?>' ? 'ring-4 ring-offset-2 <?php echo $classes['ring']; ?>' : ''">
                            <i x-show="color === '<?php echo $key; ?>'" class="fa-solid fa-check text-white text-xs"></i>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <p class="text-xs text-slate-400 mt-2">Define a cor principal do menu lateral e destaques.</p>
                </div>

                <!-- Ícone -->
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-3">Brasão da Família</label>
                    <div class="flex flex-wrap gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100">
                        <?php foreach($icons as $ico): ?>
                        <button type="button" @click="icon = '<?php echo $ico; ?>'" 
                                class="w-10 h-10 rounded-lg flex items-center justify-center text-lg transition-all hover:bg-white hover:shadow-sm"
                                :class="icon === '<?php echo $ico; ?>' ? 'bg-white shadow-md text-indigo-600 scale-110' : 'text-slate-400'">
                            <i class="fa-solid <?php echo $ico; ?>"></i>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Preview -->
            <div class="mt-8 pt-6 border-t border-slate-100">
                <p class="text-xs font-bold text-slate-400 uppercase mb-4">Pré-visualização do Menu</p>
                <div class="w-64 rounded-r-2xl overflow-hidden shadow-xl">
                    <div class="h-16 flex items-center px-6 gap-3 transition-colors duration-300" 
                         :class="{
                            'bg-slate-900': true
                         }">
                        <!-- Icon Preview -->
                        <div class="w-8 h-8 rounded-lg flex items-center justify-center text-white font-bold transition-colors duration-300"
                             :class="{
                                'bg-indigo-600': color === 'indigo',
                                'bg-emerald-600': color === 'emerald',
                                'bg-rose-600': color === 'rose',
                                'bg-amber-500': color === 'amber',
                                'bg-sky-600': color === 'sky',
                                'bg-slate-600': color === 'slate',
                             }">
                            <i class="fa-solid" :class="icon"></i>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-white">Nobl$</h2>
                            <span class="text-[8px] uppercase tracking-widest font-bold transition-colors duration-300"
                                  :class="{
                                    'text-indigo-400': color === 'indigo',
                                    'text-emerald-400': color === 'emerald',
                                    'text-rose-400': color === 'rose',
                                    'text-amber-400': color === 'amber',
                                    'text-sky-400': color === 'sky',
                                    'text-slate-400': color === 'slate',
                                  }">Dynasty</span>
                        </div>
                    </div>
                    <div class="bg-slate-900 h-32 p-4 space-y-2">
                        <div class="h-8 w-3/4 bg-white/5 rounded-lg"></div>
                        <div class="h-8 w-full rounded-lg border flex items-center px-3 gap-2 transition-colors duration-300"
                             :class="{
                                'bg-indigo-600/10 text-indigo-400 border-indigo-600/20': color === 'indigo',
                                'bg-emerald-600/10 text-emerald-400 border-emerald-600/20': color === 'emerald',
                                'bg-rose-600/10 text-rose-400 border-rose-600/20': color === 'rose',
                                'bg-amber-500/10 text-amber-400 border-amber-500/20': color === 'amber',
                                'bg-sky-600/10 text-sky-400 border-sky-600/20': color === 'sky',
                                'bg-slate-600/10 text-slate-300 border-slate-500/20': color === 'slate',
                             }">
                             <div class="w-4 h-4 rounded-full bg-current opacity-50"></div>
                             <div class="w-20 h-2 rounded bg-current opacity-50"></div>
                        </div>
                        <div class="h-8 w-1/2 bg-white/5 rounded-lg"></div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>