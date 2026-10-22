
<?php
$stmt = $pdo->prepare("SELECT id, nome, email, tipo_perfil, foto_perfil, renda_estimada, dia_recebimento_renda FROM usuarios WHERE familia_id = ? AND deleted_at IS NULL ORDER BY tipo_perfil");
$stmt->execute([$LOGGED_IN_FAMILY_ID]);
$familyMembers = $stmt->fetchAll();

function get_payday_label($day) {
    if ($day == 101) return "5º Dia Útil";
    if ($day == 102) return "Último Dia Útil";
    return $day ? "Dia $day" : "Não definido";
}
?>
<div x-data="familyPage()">
    <div class="space-y-8 animate-fade-in">
        
        <?php if ($CURRENT_USER['tipo_perfil'] === 'GUARDIÃO'): ?>
        <div class="bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
            <h3 class="font-bold text-xl text-slate-800 mb-4">Convidar para a Dinastia</h3>
            <form method="POST" action="index.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
                <input type="hidden" name="action" value="add_member">
                <div><label class="text-xs font-medium">Nome</label><input type="text" name="nome" placeholder="Nome do membro" class="w-full mt-1 p-3 border rounded-lg" required></div>
                <div><label class="text-xs font-medium">E-mail</label><input type="email" name="email" placeholder="E-mail" class="w-full mt-1 p-3 border rounded-lg" required></div>
                <div><label class="text-xs font-medium">Renda Estimada</label><input type="text" oninput="formatMoney(this)" name="renda_estimada" placeholder="R$" class="w-full mt-1 p-3 border rounded-lg"></div>
                <div>
                    <label class="text-xs font-medium">Dia Recebimento</label>
                    <select name="dia_recebimento_renda" class="w-full mt-1 p-3 border rounded-lg bg-white">
                        <option value="">Selecione...</option>
                        <option value="101">5º Dia Útil</option>
                        <option value="102">Último Dia Útil</option>
                        <?php for($i=1; $i<=31; $i++): ?><option value="<?php echo $i; ?>">Dia <?php echo $i; ?></option><?php endfor; ?>
                    </select>
                </div>
                <button type="submit" class="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all lg:col-span-4 mt-4">ENVIAR CONVITE</button>
            </form>
        </div>
        <?php endif; ?>

        <div>
            <h3 class="font-bold text-xl text-slate-800 mb-4">Membros da Corte</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                <?php foreach ($familyMembers as $member): ?>
                <div class="bg-white rounded-2xl border border-slate-200 p-6 text-center shadow-sm hover:shadow-lg transition-shadow duration-300 relative group">
                    <?php if ($CURRENT_USER['tipo_perfil'] === 'GUARDIÃO'): ?>
                    <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button @click='openEditModal(<?php echo json_encode($member); ?>)' class="text-indigo-400 hover:text-indigo-600 p-2"><i class="fa-solid fa-pen"></i></button>
                        <?php if ($member['id'] != $CURRENT_USER['id']): ?>
                        <form method="POST" action="index.php" onsubmit="return confirm('Tem certeza que deseja remover este membro?');">
                            <input type="hidden" name="action" value="delete_member">
                            <input type="hidden" name="usuario_id" value="<?php echo $member['id']; ?>">
                            <button type="submit" class="text-red-400 hover:text-red-600 p-2"><i class="fa-solid fa-user-xmark"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <img src="<?php echo htmlspecialchars($member['foto_perfil']); ?>" class="w-24 h-24 rounded-full mx-auto mb-4 border-4 border-slate-200 object-cover" alt="Foto de <?php echo htmlspecialchars($member['nome']); ?>">
                    <p class="font-bold text-slate-800"><?php echo htmlspecialchars($member['nome']); ?></p>
                    <p class="text-xs text-slate-500 mb-3"><?php echo htmlspecialchars($member['email']); ?></p>
                    <span class="text-[10px] font-bold px-2 py-1 rounded-full uppercase bg-indigo-100 text-indigo-700"><?php echo htmlspecialchars($member['tipo_perfil']); ?></span>
                    <?php if($member['renda_estimada']): ?>
                        <div class="mt-4 border-t pt-3">
                            <p class="text-xs text-slate-400">Renda: <span class="font-bold text-emerald-600"><?php echo format_currency($member['renda_estimada'], $CURRENT_USER['moeda']); ?></span></p>
                            <p class="text-[10px] text-slate-400">Recebimento: <?php echo get_payday_label($member['dia_recebimento_renda']); ?></p>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Modal Editar Membro -->
    <div x-show="modal.open" x-cloak class="fixed inset-0 bg-slate-900/40 z-[100] flex items-center justify-center">
        <form method="POST" action="index.php" class="bg-white rounded-2xl p-6 w-full max-w-md space-y-4 shadow-2xl" @click.away="modal.open = false">
            <input type="hidden" name="action" value="edit_member">
            <input type="hidden" name="usuario_id" :value="modal.data.id">
            <h3 class="font-bold text-lg">Editar Membro</h3>
            <div><label class="text-xs">Nome</label><input name="nome" x-model="modal.data.nome" type="text" class="w-full p-2 border rounded" required></div>
            <div><label class="text-xs">Renda</label><input name="renda_estimada" x-model="modal.data.renda_estimada" oninput="formatMoney(this)" type="text" class="w-full p-2 border rounded"></div>
            <div>
                <label class="text-xs">Dia Recebimento</label>
                <select name="dia_recebimento_renda" x-model="modal.data.dia_recebimento_renda" class="w-full p-2 border rounded bg-white">
                     <option value="">Selecione...</option>
                     <option value="101">5º Dia Útil</option>
                     <option value="102">Último Dia Útil</option>
                     <?php for($i=1; $i<=31; $i++): ?><option value="<?php echo $i; ?>">Dia <?php echo $i; ?></option><?php endfor; ?>
                </select>
            </div>
             <div>
                <label class="text-xs">Perfil</label>
                <select name="tipo_perfil" x-model="modal.data.tipo_perfil" class="w-full p-2 border rounded bg-white">
                     <option value="GUARDIÃO">Guardião</option>
                     <option value="SÊNIOR">Sênior</option>
                     <option value="JOVEM">Jovem</option>
                </select>
            </div>
            <div class="flex gap-2"><button type="button" @click="modal.open=false" class="w-full py-2 bg-slate-200 rounded font-bold">Cancelar</button><button type="submit" class="w-full py-2 bg-indigo-600 text-white rounded font-bold">Salvar</button></div>
        </form>
    </div>
</div>

<script>
function familyPage() {
    return {
        modal: { open: false, data: {} },
        openEditModal(member) {
            let cleanData = {...member};
            if(cleanData.renda_estimada) {
                cleanData.renda_estimada = new Intl.NumberFormat('pt-BR', {minimumFractionDigits: 2}).format(cleanData.renda_estimada);
            }
            this.modal.data = cleanData;
            this.modal.open = true;
        }
    }
}
</script>