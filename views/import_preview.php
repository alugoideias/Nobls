
<?php
if (!isset($_SESSION['import_staging']) || empty($_SESSION['import_staging']['transactions'])) {
    echo "<div class='p-8 text-center text-slate-500'><p>Nenhum dado importado para revisão.</p><a href='index.php?page=import' class='text-indigo-600 underline'>Voltar</a></div>";
    return;
}

$staging = $_SESSION['import_staging'];
$transactions = $staging['transactions'];
$target_conta_id = $staging['target_conta_id'];
$target_cartao_id = $staging['target_cartao_id'];

// Busca categorias para o dropdown
$stmtCats = $pdo->prepare("SELECT id, nome, tipo FROM categorias WHERE familia_id = ? ORDER BY nome");
$stmtCats->execute([$LOGGED_IN_FAMILY_ID]);
$categories = $stmtCats->fetchAll();

// Função auxiliar para checar duplicata no banco (Mais flexível na descrição)
function checkDuplicate($pdo, $familyId, $data) {
    // Checa valor exato, data exata e parte da descrição
    $descPart = substr($data['descricao'], 0, 10); 
    $stmt = $pdo->prepare("SELECT id FROM lancamentos WHERE familia_id = ? AND data_competencia = ? AND valor = ? AND descricao LIKE ? AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([$familyId, $data['data'], $data['valor'], "%$descPart%"]);
    return $stmt->fetch() ? true : false;
}
?>

<div class="space-y-6" x-data="{ selectAll: false }">
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
        <div>
            <h2 class="text-xl font-bold text-slate-800">Revisar Importação</h2>
            <p class="text-sm text-slate-500">Verifique os dados abaixo. Itens amarelos podem ser duplicados.</p>
        </div>
        <div class="flex gap-2 w-full md:w-auto">
            <a href="index.php?page=import" class="flex-1 md:flex-none text-center px-4 py-3 bg-slate-200 text-slate-600 rounded-xl font-bold text-xs">CANCELAR</a>
            <button type="submit" form="previewForm" class="flex-1 md:flex-none px-4 py-3 bg-indigo-600 text-white rounded-xl font-bold text-xs hover:bg-indigo-700 shadow-lg shadow-indigo-600/20">CONFIRMAR E SALVAR</button>
        </div>
    </div>

    <form id="previewForm" action="index.php" method="POST" class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <input type="hidden" name="action" value="confirm_import">
        <input type="hidden" name="conta_id" value="<?php echo $target_conta_id; ?>">
        <input type="hidden" name="cartao_id" value="<?php echo $target_cartao_id; ?>">

        <div class="overflow-x-auto">
            <table class="w-full text-left min-w-[800px]">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 w-16 text-center text-xs font-bold text-slate-500">IGNORAR</th>
                        <th class="px-4 py-3 text-xs font-bold text-slate-500 uppercase">Data</th>
                        <th class="px-4 py-3 text-xs font-bold text-slate-500 uppercase w-1/3">Descrição</th>
                        <th class="px-4 py-3 text-xs font-bold text-slate-500 uppercase">Valor</th>
                        <th class="px-4 py-3 text-xs font-bold text-slate-500 uppercase">Categoria</th>
                        <th class="px-4 py-3 text-xs font-bold text-slate-500 uppercase">Parcelas</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($transactions as $idx => $item): 
                        $isDuplicate = checkDuplicate($pdo, $LOGGED_IN_FAMILY_ID, $item);
                        $hasInstallments = isset($item['parcela_total']) && $item['parcela_total'] > 1;
                        $remainingInstallments = $hasInstallments ? ($item['parcela_total'] - $item['parcela_atual']) : 0;
                        $item['parcela_atual'] = $item['parcela_atual'] ?? 1;
                        $item['parcela_total'] = $item['parcela_total'] ?? 1;
                    ?>
                    <tr class="hover:bg-slate-50 transition-colors <?php echo $isDuplicate ? 'bg-amber-50' : ''; ?>">
                        
                        <!-- Coluna Ignorar -->
                        <td class="px-4 py-3 text-center">
                            <input type="checkbox" name="items[<?php echo $idx; ?>][ignore]" value="1" 
                                   class="w-5 h-5 rounded border-slate-300 text-slate-400 focus:ring-slate-500 cursor-pointer"
                                   <?php echo $isDuplicate ? 'checked' : ''; ?>>
                        </td>

                        <!-- Dados Ocultos -->
                        <input type="hidden" name="items[<?php echo $idx; ?>][tipo]" value="<?php echo $item['tipo']; ?>">
                        <input type="hidden" name="items[<?php echo $idx; ?>][parcela_atual]" value="<?php echo $item['parcela_atual']; ?>">
                        <input type="hidden" name="items[<?php echo $idx; ?>][parcela_total]" value="<?php echo $item['parcela_total']; ?>">

                        <!-- Data -->
                        <td class="px-4 py-3">
                            <input type="date" name="items[<?php echo $idx; ?>][data]" value="<?php echo $item['data']; ?>" 
                                   class="w-full p-1.5 border border-slate-200 rounded text-sm bg-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                        </td>

                        <!-- Descrição -->
                        <td class="px-4 py-3">
                            <input type="text" name="items[<?php echo $idx; ?>][descricao]" value="<?php echo htmlspecialchars($item['descricao']); ?>" 
                                   class="w-full p-1.5 border border-slate-200 rounded text-sm bg-white font-medium focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                            <?php if($isDuplicate): ?>
                                <p class="text-[10px] text-amber-600 font-bold mt-1 flex items-center gap-1"><i class="fa-solid fa-triangle-exclamation"></i> Já existe no sistema</p>
                            <?php endif; ?>
                        </td>

                        <!-- Valor -->
                        <td class="px-4 py-3">
                            <input type="number" step="0.01" name="items[<?php echo $idx; ?>][valor]" value="<?php echo $item['valor']; ?>" 
                                   class="w-24 p-1.5 border border-slate-200 rounded text-sm bg-white font-bold text-slate-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                        </td>

                        <!-- Categoria -->
                        <td class="px-4 py-3">
                            <select name="items[<?php echo $idx; ?>][categoria_id]" class="w-full p-1.5 border border-slate-200 rounded text-sm bg-white focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none">
                                <option value="">Sem Categoria</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['id']; ?>" <?php echo ($item['categoria_id'] == $cat['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>

                        <!-- Parcelas Futuras -->
                        <td class="px-4 py-3">
                            <?php if($hasInstallments): ?>
                                <div class="flex flex-col gap-1">
                                    <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-1 rounded-md font-bold w-fit">
                                        <?php echo $item['parcela_atual']; ?>/<?php echo $item['parcela_total']; ?>
                                    </span>
                                    <?php if($remainingInstallments > 0): ?>
                                    <label class="flex items-center gap-1.5 cursor-pointer text-xs text-slate-600 hover:text-indigo-600 bg-slate-50 px-2 py-1 rounded border border-slate-200">
                                        <input type="checkbox" name="items[<?php echo $idx; ?>][generate_future]" value="1" checked class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 w-3 h-3">
                                        Criar +<?php echo $remainingInstallments; ?>
                                    </label>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <span class="text-xs text-slate-300 font-bold">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </form>
</div>
