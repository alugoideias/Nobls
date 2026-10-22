
<?php
// Busca contas e cartões para o select de destino
$stmtCards = $pdo->prepare("SELECT id, nome_cartao FROM cartoes_credito WHERE familia_id = ? AND deleted_at IS NULL");
$stmtCards->execute([$LOGGED_IN_FAMILY_ID]);
$cards = $stmtCards->fetchAll();

$stmtAccs = $pdo->prepare("SELECT id, nome_banco FROM contas_bancarias WHERE familia_id = ? AND deleted_at IS NULL");
$stmtAccs->execute([$LOGGED_IN_FAMILY_ID]);
$accounts = $stmtAccs->fetchAll();
?>
<div class="space-y-6 max-w-2xl mx-auto" x-data="{ loading: false }">
    <div class="bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 mb-2">Importar Fatura ou Extrato</h2>
        <p class="text-slate-500 text-sm mb-6">Envie a fatura do seu cartão (PDF) ou extrato bancário. A Inteligência Artificial lerá os itens e categorizará automaticamente.</p>

        <form action="import_handler.php" method="POST" enctype="multipart/form-data" @submit="loading = true">
            
            <div class="mb-6 text-left">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Vincular a:</label>
                <select name="target_id" class="w-full p-3 border rounded-lg bg-slate-50 text-slate-700 font-medium focus:ring-2 focus:ring-indigo-500 outline-none" required>
                    <option value="">Selecione o destino...</option>
                    <optgroup label="Cartões de Crédito">
                        <?php foreach($cards as $c): ?>
                            <option value="card_<?php echo $c['id']; ?>">&#xf09d; <?php echo htmlspecialchars($c['nome_cartao']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="Contas Bancárias">
                        <?php foreach($accounts as $a): ?>
                            <option value="account_<?php echo $a['id']; ?>">&#xf19c; <?php echo htmlspecialchars($a['nome_banco']); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>

             <label for="extrato-upload" class="w-full cursor-pointer bg-slate-50 border-2 border-dashed border-slate-300 rounded-xl p-12 text-center hover:bg-indigo-50 hover:border-indigo-400 transition-colors flex flex-col items-center justify-center group">
                <div class="text-indigo-400 group-hover:text-indigo-600 transition-colors"><i class="fa-solid fa-file-invoice-dollar text-5xl"></i></div>
                <p class="font-bold text-slate-700 mt-4 group-hover:text-indigo-700">Clique para selecionar o PDF</p>
                <p class="text-xs text-slate-400 mt-2">Suporta faturas de cartão e extratos em PDF.</p>
            </label>
            <input type="file" id="extrato-upload" name="extrato" class="hidden" accept=".pdf" required onchange="document.querySelector('.font-bold.text-slate-700').textContent = this.files[0].name">
            
            <button type="submit" class="w-full mt-6 py-4 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all active:scale-95 disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2" :disabled="loading">
                <span x-show="!loading">PROCESSAR COM IA</span>
                <span x-show="loading"><i class="fa-solid fa-circle-notch fa-spin"></i> LENDO FATURA...</span>
            </button>
        </form>
    </div>
    
    <div class="bg-indigo-50 border border-indigo-100 p-4 rounded-xl flex gap-3 items-start">
        <i class="fa-solid fa-robot text-indigo-500 mt-1"></i>
        <div>
            <h4 class="font-bold text-indigo-800 text-sm">Como funciona?</h4>
            <p class="text-xs text-indigo-600 mt-1">Nossa IA analisa o PDF visualmente, identifica datas, estabelecimentos e valores, e tenta vincular automaticamente às suas categorias cadastradas (ex: Uber -> Transporte).</p>
        </div>
    </div>
</div>
