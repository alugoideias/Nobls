<?php
// Busca produtos cadastrados
$stmt = $pdo->prepare("SELECT * FROM mercado_produtos WHERE familia_id = ? AND deleted_at IS NULL ORDER BY proxima_compra_prevista ASC");
$stmt->execute([$LOGGED_IN_FAMILY_ID]);
$produtos = $stmt->fetchAll();

// Calcula status da próxima compra
$hoje = new DateTime();
?>

<div x-data="{ modal: { open: false, isProduct: true } }" class="space-y-8 animate-fade-in">
    <!-- Header -->
    <div class="flex flex-col md:flex-row justify-between items-end gap-4 border-b border-slate-200 pb-4">
        <div>
            <h2 class="text-2xl font-bold text-slate-800">Gestão de Mercado</h2>
            <p class="text-slate-500 text-sm">Controle de despensa e previsão de compras.</p>
        </div>
        <button @click="modal.open = true" class="px-6 py-3 bg-indigo-600 text-white rounded-xl font-bold text-sm shadow-lg shadow-indigo-600/20 hover:bg-indigo-700 transition-all active:scale-95 flex items-center gap-2">
            <i class="fa-solid fa-plus"></i> NOVO PRODUTO
        </button>
    </div>

    <!-- Lista de Compras Inteligente -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        
        <!-- Precisa Comprar (Vencidos ou Próximos) -->
        <div class="bg-white rounded-3xl border border-slate-200 p-6 shadow-sm">
            <h3 class="font-bold text-lg text-slate-800 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-basket-shopping text-rose-500"></i> Repor Estoque
            </h3>
            
            <?php 
            $hasItems = false;
            foreach($produtos as $prod): 
                $vencimento = $prod['proxima_compra_prevista'] ? new DateTime($prod['proxima_compra_prevista']) : null;
                if(!$vencimento || $vencimento <= (clone $hoje)->modify('+3 days')):
                    $hasItems = true;
                    $diff = $vencimento ? $hoje->diff($vencimento)->days : 0;
                    $isLate = $vencimento && $hoje > $vencimento;
            ?>
            <div class="flex items-center justify-between p-3 border-b border-slate-50 last:border-0 hover:bg-slate-50 transition-colors rounded-lg group">
                <div>
                    <p class="font-bold text-slate-700"><?php echo htmlspecialchars($prod['nome']); ?></p>
                    <p class="text-xs text-slate-400">Última compra: <?php echo $prod['ultima_compra'] ? date('d/m', strtotime($prod['ultima_compra'])) : 'Nunca'; ?></p>
                </div>
                <div class="flex items-center gap-3">
                    <?php if($isLate): ?>
                        <span class="text-[10px] bg-rose-100 text-rose-600 font-bold px-2 py-1 rounded">Venceu</span>
                    <?php else: ?>
                        <span class="text-[10px] bg-amber-100 text-amber-600 font-bold px-2 py-1 rounded">Em <?php echo $diff; ?> dias</span>
                    <?php endif; ?>
                    
                    <form method="POST" action="index.php">
                        <input type="hidden" name="action" value="log_market_purchase">
                        <input type="hidden" name="produto_id" value="<?php echo $prod['id']; ?>">
                        <button type="submit" class="w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 hover:bg-indigo-600 hover:text-white flex items-center justify-center transition-all shadow-sm" title="Marcar como Comprado Hoje">
                            <i class="fa-solid fa-check"></i>
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; endforeach; ?>
            
            <?php if(!$hasItems): ?>
                <div class="text-center py-8 text-slate-400">
                    <i class="fa-regular fa-thumbs-up text-3xl mb-2"></i>
                    <p class="text-xs">Tudo abastecido por enquanto!</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- Todos os Produtos (Estoque) -->
        <div class="bg-slate-50 rounded-3xl border border-slate-200 p-6">
            <h3 class="font-bold text-lg text-slate-700 mb-4 flex items-center gap-2">
                <i class="fa-solid fa-cubes-stacked text-slate-400"></i> Despensa Geral
            </h3>
            <div class="space-y-2 max-h-[400px] overflow-y-auto pr-2 custom-scrollbar">
                <?php foreach($produtos as $prod): 
                     $vencimento = $prod['proxima_compra_prevista'] ? new DateTime($prod['proxima_compra_prevista']) : null;
                     if($vencimento && $vencimento <= (clone $hoje)->modify('+3 days')) continue; // Já mostrado na outra lista
                ?>
                <div class="bg-white p-3 rounded-xl border border-slate-200 flex justify-between items-center group">
                    <div>
                        <p class="font-bold text-sm text-slate-700"><?php echo htmlspecialchars($prod['nome']); ?></p>
                        <p class="text-[10px] text-slate-400">Média: a cada <?php echo $prod['intervalo_medio_dias']; ?> dias</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[10px] text-slate-400 bg-slate-100 px-2 py-1 rounded">Prev: <?php echo $prod['proxima_compra_prevista'] ? date('d/m', strtotime($prod['proxima_compra_prevista'])) : '?'; ?></span>
                        
                        <form method="POST" action="index.php" class="opacity-0 group-hover:opacity-100 transition-opacity">
                            <input type="hidden" name="action" value="log_market_purchase">
                            <input type="hidden" name="produto_id" value="<?php echo $prod['id']; ?>">
                            <button type="submit" class="text-indigo-400 hover:text-indigo-600 p-1" title="Comprar"><i class="fa-solid fa-cart-plus"></i></button>
                        </form>
                        
                        <form method="POST" action="index.php" onsubmit="return confirm('Excluir produto?');" class="opacity-0 group-hover:opacity-100 transition-opacity">
                            <input type="hidden" name="action" value="delete_market_product">
                            <input type="hidden" name="id" value="<?php echo $prod['id']; ?>">
                            <button type="submit" class="text-slate-300 hover:text-red-500 p-1"><i class="fa-solid fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if(empty($produtos)): ?>
                    <p class="text-center text-xs text-slate-400 italic">Cadastre produtos para começar a rastrear.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Novo Produto -->
    <div x-show="modal.open" x-cloak class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/40 backdrop-blur-sm p-4">
        <form action="index.php" method="POST" class="bg-white rounded-2xl p-6 w-full max-w-sm shadow-2xl" @click.away="modal.open = false">
            <input type="hidden" name="action" value="add_market_product">
            
            <div class="flex justify-between items-center mb-4">
                <h3 class="font-bold text-lg text-slate-800">Novo Item Recorrente</h3>
                <button type="button" @click="modal.open = false" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-times"></i></button>
            </div>
            
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase">Nome do Produto</label>
                    <input type="text" name="nome" placeholder="Ex: Arroz 5kg, Sabão em Pó" class="w-full p-3 border rounded-lg mt-1" required>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 uppercase">Categoria (Opcional)</label>
                    <input type="text" name="categoria" placeholder="Ex: Limpeza, Alimentos" class="w-full p-3 border rounded-lg mt-1">
                </div>
                <button type="submit" class="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 shadow-lg mt-2">CADASTRAR</button>
            </div>
        </form>
    </div>
</div>
