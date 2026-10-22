
<?php
// Busca Pets
$stmt = $pdo->prepare("SELECT * FROM pets WHERE familia_id = ? AND deleted_at IS NULL ORDER BY nome");
$stmt->execute([$LOGGED_IN_FAMILY_ID]);
$pets = $stmt->fetchAll();

// Dados para os selects (Categorias, Contas, Cartões) - Usado no Modal de Vacina
$stmtCats = $pdo->prepare("SELECT id, nome, grupo FROM categorias WHERE familia_id = ? AND tipo = 'DESPESA' AND deleted_at IS NULL ORDER BY grupo, nome");
$stmtCats->execute([$LOGGED_IN_FAMILY_ID]);
$categories = $stmtCats->fetchAll();

// Agrupa categorias para o select PHP
$groupedCats = [];
foreach($categories as $cat) {
    $g = $cat['grupo'] ?: 'Geral';
    $groupedCats[$g][] = $cat;
}
ksort($groupedCats);

$stmtAccs = $pdo->prepare("SELECT id, nome_banco FROM contas_bancarias WHERE familia_id = ? AND deleted_at IS NULL");
$stmtAccs->execute([$LOGGED_IN_FAMILY_ID]);
$accounts = $stmtAccs->fetchAll();

$stmtCards = $pdo->prepare("SELECT id, nome_cartao FROM cartoes_credito WHERE familia_id = ? AND deleted_at IS NULL");
$stmtCards->execute([$LOGGED_IN_FAMILY_ID]);
$cards = $stmtCards->fetchAll();

function calculateAge($birthDate) {
    if (!$birthDate) return 'Idade desconhecida';
    $bday = new DateTime($birthDate);
    $today = new DateTime();
    $diff = $today->diff($bday);
    if ($diff->y > 0) return $diff->y . ' ano(s) e ' . $diff->m . ' mês(es)';
    return $diff->m . ' mês(es)';
}
?>

<div x-data="petsPage()">
    <!-- Conteúdo da Página -->
    <div class="space-y-8 animate-fade-in">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-end gap-4">
            <div>
                <h2 class="text-2xl font-bold text-slate-800">Meus Pets</h2>
                <p class="text-slate-500">Acompanhe a saúde, vacinas e custos dos seus companheiros.</p>
            </div>
            <button @click="openModal()" class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm hover:bg-indigo-700 shadow-lg shadow-indigo-600/20 transition-all active:scale-95 flex items-center gap-2">
                <i class="fa-solid fa-paw"></i> NOVO PET
            </button>
        </div>

        <!-- Grid de Pets -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($pets as $pet): 
                // Busca a última vacina
                $stmtVac = $pdo->prepare("SELECT nome_vacina, data_aplicacao FROM pet_vacinacoes WHERE pet_id = ? AND deleted_at IS NULL ORDER BY data_aplicacao DESC LIMIT 1");
                $stmtVac->execute([$pet['id']]);
                $lastVac = $stmtVac->fetch();
            ?>
            <div class="bg-white rounded-3xl border border-slate-200 p-6 flex flex-col relative group hover:shadow-xl transition-all duration-300 overflow-hidden">
                <div class="absolute top-0 right-0 opacity-5 pointer-events-none transform translate-x-10 -translate-y-10 scale-150"><i class="fa-solid fa-paw text-9xl text-slate-800"></i></div>

                <div class="flex items-center gap-5 mb-6 relative z-10">
                    <div class="w-20 h-20 rounded-2xl bg-gradient-to-br from-indigo-100 to-indigo-50 text-indigo-500 flex items-center justify-center text-3xl shadow-inner border border-white"><i class="fa-solid fa-dog"></i></div>
                    <div>
                        <h3 class="font-bold text-xl text-slate-800 leading-tight"><?php echo htmlspecialchars($pet['nome']); ?></h3>
                        <p class="text-sm text-slate-500 font-medium"><?php echo htmlspecialchars($pet['raca']); ?></p>
                        <span class="text-[10px] bg-indigo-50 text-indigo-600 px-2.5 py-1 rounded-full font-bold uppercase tracking-wide inline-block mt-2 border border-indigo-100"><?php echo calculateAge($pet['data_nascimento']); ?></span>
                    </div>
                </div>
                
                <div class="space-y-3 mb-8 relative z-10 bg-slate-50 p-4 rounded-xl border border-slate-100">
                    <div class="flex justify-between text-xs"><span class="text-slate-400 font-bold uppercase tracking-wider">Peso</span><span class="font-bold text-slate-700"><?php echo $pet['peso']; ?> kg</span></div>
                    <div class="w-full h-px bg-slate-200"></div>
                    <div class="flex justify-between text-xs"><span class="text-slate-400 font-bold uppercase tracking-wider">Última Vacina</span><span class="font-bold text-slate-700"><?php echo $lastVac ? htmlspecialchars($lastVac['nome_vacina']) : 'Nenhuma'; ?></span></div>
                </div>

                <div class="mt-auto grid grid-cols-5 gap-2 relative z-10">
                    <button @click='openVaccineModal(<?php echo $pet['id']; ?>, "<?php echo addslashes($pet['nome']); ?>")' class="col-span-3 py-3 bg-emerald-500 text-white rounded-xl font-bold text-xs hover:bg-emerald-600 transition-colors shadow-lg shadow-emerald-500/20 flex items-center justify-center gap-2"><i class="fa-solid fa-syringe"></i> VACINAS</button>
                    <button @click='openModal(<?php echo json_encode($pet); ?>)' class="col-span-1 py-3 bg-slate-100 text-slate-600 rounded-xl hover:bg-indigo-100 hover:text-indigo-600 transition-colors"><i class="fa-solid fa-pen"></i></button>
                    <form action="index.php" method="POST" onsubmit="return confirm('Excluir este pet?');" class="col-span-1 h-full"><input type="hidden" name="action" value="delete_pet"><input type="hidden" name="pet_id" value="<?php echo $pet['id']; ?>"><button type="submit" class="w-full h-full bg-slate-100 text-slate-600 rounded-xl hover:bg-red-100 hover:text-red-500 transition-colors"><i class="fa-solid fa-trash"></i></button></form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Modal Novo/Editar Pet -->
    <div x-show="modal.open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
        <form action="index.php" method="POST" class="bg-white rounded-3xl p-8 w-full max-w-md space-y-5 shadow-2xl relative overflow-hidden" @click.away="modal.open = false">
            <input type="hidden" name="action" :value="modal.isEdit ? 'edit_pet' : 'add_pet'">
            <input type="hidden" name="pet_id" :value="modal.data.id">
            
            <div class="flex justify-between items-center pb-2">
                <h3 class="font-black text-2xl text-slate-800" x-text="modal.isEdit ? 'Editar Pet' : 'Novo Pet'"></h3>
                <button type="button" @click="modal.open = false" class="w-8 h-8 rounded-full bg-slate-100 text-slate-400 hover:bg-slate-200 flex items-center justify-center transition-colors"><i class="fa-solid fa-times"></i></button>
            </div>
            
            <div><label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Nome do Pet</label><input type="text" name="nome" x-model="modal.data.nome" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 font-bold text-slate-700" placeholder="Ex: Rex" required></div>
            <div><label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Raça</label><input type="text" name="raca" x-model="modal.data.raca" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 text-slate-700" placeholder="Ex: Labrador"></div>
            <div class="grid grid-cols-2 gap-4">
                <div><label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Nascimento</label><input type="date" name="data_nascimento" x-model="modal.data.data_nascimento" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 text-slate-700"></div>
                <div><label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Peso (Kg)</label><input type="number" step="0.01" name="peso" x-model="modal.data.peso" class="w-full p-4 border border-slate-200 rounded-xl mt-1.5 text-slate-700" placeholder="0.00"></div>
            </div>
            <div class="pt-4"><button type="submit" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-black tracking-wide hover:bg-indigo-700 shadow-lg shadow-indigo-600/20 active:scale-95 transition-all">SALVAR PET</button></div>
        </form>
    </div>

    <!-- Modal Vacinas -->
    <div x-show="vaccineModal.open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
        <div class="bg-white rounded-3xl w-full max-w-4xl shadow-2xl flex flex-col lg:flex-row overflow-hidden max-h-[90vh]" @click.away="vaccineModal.open = false">
            <div class="w-full lg:w-1/3 bg-slate-50 p-6 lg:p-8 border-r border-slate-200 overflow-y-auto">
                <div class="mb-6"><h3 class="font-bold text-xl text-slate-800">Nova Vacina</h3><p class="text-xs text-slate-500 mt-1">Registre uma aplicação para <span x-text="vaccineModal.petName" class="font-bold text-indigo-600"></span>.</p></div>
                
                <form action="index.php" method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="add_vaccine"><input type="hidden" name="pet_id" :value="vaccineModal.petId">
                    <div><label class="text-[10px] font-bold text-slate-500 uppercase">Nome da Vacina</label><input type="text" name="nome_vacina" placeholder="Ex: V8, Antirrábica" class="w-full p-3 bg-white border border-slate-200 rounded-xl mt-1 text-sm" required></div>

                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="text-[10px] font-bold text-slate-500 uppercase">Data Aplicação</label><input type="date" name="data_aplicacao" class="w-full p-3 bg-white border border-slate-200 rounded-xl mt-1 text-sm" required></div>
                        <div>
                            <label class="text-[10px] font-bold text-slate-500 uppercase">Reforço (Médico)</label>
                            <select name="recorrencia_vacina" class="w-full p-3 bg-white border border-slate-200 rounded-xl mt-1 text-sm">
                                <option value="UNICA">Não (Única)</option>
                                <option value="ANUAL">Anual</option>
                                <option value="SEMESTRAL">6 Meses</option>
                                <option value="TRIMESTRAL">3 Meses</option>
                            </select>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-slate-200 mt-4">
                        <label class="flex items-center gap-2 cursor-pointer mb-2"><input type="checkbox" x-model="vaccineModal.hasCost" class="w-4 h-4 text-indigo-600 rounded border-gray-300 focus:ring-indigo-500"><span class="text-xs font-bold text-slate-600">Houve custo financeiro?</span></label>
                        
                        <div x-show="vaccineModal.hasCost" x-transition class="space-y-3 pl-2 border-l-2 border-indigo-100">
                            <div><label class="text-[10px] font-bold text-slate-500 uppercase">Valor (R$)</label><input type="text" name="custo" x-model="vaccineModal.cost" oninput="formatMoney(this)" placeholder="0,00" class="w-full p-3 bg-white border border-slate-200 rounded-xl mt-1 text-sm"></div>
                            
                            <!-- Recorrência Financeira -->
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="text-[10px] font-bold text-slate-500 uppercase">Pagamento</label>
                                    <select name="recorrencia_financeira" x-model="vaccineModal.paymentMethod" class="w-full p-3 bg-white border border-slate-200 rounded-xl mt-1 text-sm">
                                        <option value="UNICA">À Vista</option>
                                        <option value="PARCELADO">Parcelado</option>
                                    </select>
                                </div>
                                <div x-show="vaccineModal.paymentMethod === 'PARCELADO'">
                                    <label class="text-[10px] font-bold text-slate-500 uppercase">Qtd. Parcelas</label>
                                    <input type="number" name="qtd_parcelas" min="2" max="24" class="w-full p-3 bg-white border border-slate-200 rounded-xl mt-1 text-sm" placeholder="Ex: 3">
                                </div>
                            </div>

                            <div>
                                <label class="text-[10px] font-bold text-slate-500 uppercase">Categoria</label>
                                <select name="categoria_id" class="w-full p-3 bg-white border border-slate-200 rounded-xl mt-1 text-sm">
                                    <?php foreach($groupedCats as $group => $cats): ?>
                                        <optgroup label="<?php echo htmlspecialchars($group); ?>">
                                            <?php foreach($cats as $cat): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nome']); ?></option><?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm hover:bg-indigo-700 shadow-md">SALVAR</button>
                </form>
            </div>
            <div class="flex-1 bg-white p-6 lg:p-8 overflow-y-auto relative">
                <button type="button" @click="vaccineModal.open = false" class="absolute top-4 right-4 text-slate-300 hover:text-slate-500 transition-colors"><i class="fa-solid fa-xmark text-2xl"></i></button>
                <div class="mb-8"><h3 class="font-bold text-xl text-slate-800">Linha do Tempo</h3><p class="text-xs text-slate-500 mt-1">Histórico e próximas doses.</p></div>
                <div id="vaccine-timeline-placeholder" class="relative pl-4"></div>
            </div>
        </div>
    </div>
</div>

<script>
function petsPage() {
    return {
        modal: { open: false, isEdit: false, data: {} },
        vaccineModal: { open: false, petId: null, petName: '', cost: 0, paymentMethod: 'UNICA', hasCost: false },
        openModal(pet = null) { this.modal.isEdit = !!pet; this.modal.data = pet ? {...pet} : { nome: '', raca: '', data_nascimento: '', peso: '' }; this.modal.open = true; },
        openVaccineModal(id, name) { this.vaccineModal.petId = id; this.vaccineModal.petName = name; this.vaccineModal.cost = ''; this.vaccineModal.hasCost = false; this.vaccineModal.open = true; this.loadVaccines(id); },
        loadVaccines(id) {
            const container = document.getElementById('vaccine-timeline-placeholder');
            container.innerHTML = '<div class="flex items-center gap-2 text-slate-400 py-10"><i class="fa-solid fa-spinner fa-spin"></i> Carregando histórico...</div>';
            fetch(`data.php?action=get_vaccines&pet_id=${id}`).then(res => res.json()).then(data => {
                if (data.length === 0) { container.innerHTML = `<div class="text-center py-12 opacity-50"><i class="fa-solid fa-syringe text-4xl mb-4 text-slate-300"></i><p class="text-sm font-bold text-slate-600">Nenhuma vacina registrada.</p></div>`; return; }
                let html = '<div class="space-y-0 border-l-2 border-slate-100 ml-3">';
                data.forEach(v => {
                    // Logic to show Future Dose if exists and > today
                    if (v.proxima_dose) {
                        const nextDate = new Date(v.proxima_dose);
                        const today = new Date();
                        if(nextDate > today) {
                             html += `<div class="relative pl-8 pb-8 group"><div class="absolute -left-[9px] top-1 w-4 h-4 bg-indigo-500 rounded-full border-4 border-white shadow-sm ring-4 ring-indigo-50"></div><div class="bg-indigo-50 border border-indigo-100 rounded-xl p-4 shadow-sm relative"><div class="flex justify-between items-start mb-1"><h4 class="font-bold text-indigo-800"><i class="fa-regular fa-calendar-check mr-1"></i> Próxima: ${v.nome_vacina}</h4></div><p class="text-xs text-indigo-600 font-bold mb-0">Agendado para ${new Date(v.proxima_dose).toLocaleDateString('pt-BR')}</p></div></div>`;
                        }
                    }
                    html += `<div class="relative pl-8 pb-8 group"><div class="absolute -left-[9px] top-1 w-4 h-4 bg-emerald-500 rounded-full border-4 border-white shadow-sm"></div><div class="bg-white border border-slate-100 rounded-xl p-4 shadow-sm group-hover:shadow-md transition-shadow relative"><div class="flex justify-between items-start mb-1"><h4 class="font-bold text-slate-800">${v.nome_vacina}</h4><form action="index.php" method="POST" onsubmit="return confirm('Apagar?');" class="opacity-0 group-hover:opacity-100 transition-opacity absolute top-2 right-2"><input type="hidden" name="action" value="delete_vaccine"><input type="hidden" name="vaccine_id" value="${v.id}"><button class="text-red-400 hover:text-red-600 text-xs"><i class="fa-solid fa-trash"></i></button></form></div><p class="text-xs text-slate-500 mb-2">Aplicada em ${new Date(v.data_aplicacao).toLocaleDateString('pt-BR')}</p>${v.custo > 0 ? `<span class="text-[10px] font-bold bg-slate-100 text-slate-600 px-2 py-1 rounded">R$ ${v.custo}</span>` : ''}</div></div>`;
                });
                html += '</div>';
                container.innerHTML = html;
            });
        }
    }
}
</script>