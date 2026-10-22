
<?php
// Busca o nível e XP da família
$stmtFamily = $pdo->prepare("SELECT nivel, xp FROM familias WHERE id = ?");
$stmtFamily->execute([$LOGGED_IN_FAMILY_ID]);
$family = $stmtFamily->fetch();
$familyLevel = $family['nivel'] ?? 1;
$familyXp = $family['xp'] ?? 0;
$xpToNextLevel = $familyLevel * 100;
$xpProgress = ($familyXp / $xpToNextLevel) * 100;


// Busca todas as conquistas e as que o usuário já desbloqueou
$allAchievements = $pdo->query("SELECT * FROM conquistas ORDER BY pontos ASC")->fetchAll();
$stmtUnlocked = $pdo->prepare("SELECT conquista_id FROM usuario_conquistas WHERE usuario_id = ?");
$stmtUnlocked->execute([$LOGGED_IN_USER_ID]);
$unlockedAchievementsIds = $stmtUnlocked->fetchAll(PDO::FETCH_COLUMN);
?>
<div class="space-y-8">
    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 mb-1">Jornada da Dinastia</h2>
        <p class="text-slate-500 text-sm mb-4">Acumule XP completando tarefas e suba o nível da sua família!</p>

        <div class="flex items-center gap-4">
            <div class="text-3xl font-black text-indigo-600 bg-indigo-100 rounded-xl w-16 h-16 flex items-center justify-center"><?php echo $familyLevel; ?></div>
            <div class="flex-1">
                <p class="text-sm font-bold">Nível <?php echo $familyLevel; ?></p>
                <div class="w-full bg-slate-200 rounded-full h-2.5 mt-1">
                    <div class="bg-indigo-600 h-2.5 rounded-full" style="width: <?php echo $xpProgress; ?>%"></div>
                </div>
                <p class="text-xs text-slate-500 mt-1"><?php echo $familyXp; ?> / <?php echo $xpToNextLevel; ?> XP para o próximo nível</p>
            </div>
        </div>
    </div>

    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200">
        <h3 class="font-bold text-slate-800 mb-4">Mural de Conquistas</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            <?php foreach ($allAchievements as $ach): 
                $isUnlocked = in_array($ach['id'], $unlockedAchievementsIds);
            ?>
            <div class="p-4 rounded-xl border <?php echo $isUnlocked ? 'bg-amber-50 border-amber-200' : 'bg-slate-50 border-slate-200'; ?>">
                <div class="flex items-start gap-4">
                    <div class="text-2xl w-12 h-12 flex items-center justify-center rounded-lg <?php echo $isUnlocked ? 'text-amber-500' : 'text-slate-400'; ?>">
                        <i class="fa-solid <?php echo htmlspecialchars($ach['icone']); ?>"></i>
                    </div>
                    <div class="flex-1">
                        <p class="font-bold <?php echo $isUnlocked ? 'text-amber-900' : 'text-slate-800'; ?>"><?php echo htmlspecialchars($ach['nome']); ?></p>
                        <p class="text-xs <?php echo $isUnlocked ? 'text-amber-700' : 'text-slate-500'; ?>"><?php echo htmlspecialchars($ach['descricao']); ?></p>
                    </div>
                    <?php if ($isUnlocked): ?>
                        <div class="text-amber-500" title="Desbloqueada!"><i class="fa-solid fa-check-circle"></i></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
