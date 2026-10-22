
<?php
$success_msg = $_GET['success'] ?? null;
$success_pass_msg = $_GET['success_pass'] ?? null;
$error_pass_msg = $_GET['error_pass'] ?? null;

// Busca contas para o select de recebimento
$stmtAcc = $pdo->prepare("SELECT id, nome_banco FROM contas_bancarias WHERE familia_id = ? AND deleted_at IS NULL");
$stmtAcc->execute([$LOGGED_IN_FAMILY_ID]);
$accounts = $stmtAcc->fetchAll();
?>
<div class="space-y-6 max-w-2xl mx-auto animate-fade-in">
    <?php if($success_msg): ?><div class="bg-emerald-100 text-emerald-800 p-3 rounded-lg text-sm flex items-center gap-2"><i class="fa-solid fa-check-circle"></i> Perfil atualizado com sucesso!</div><?php endif; ?>
    <?php if($success_pass_msg): ?><div class="bg-emerald-100 text-emerald-800 p-3 rounded-lg text-sm flex items-center gap-2"><i class="fa-solid fa-check-circle"></i> Senha alterada com sucesso!</div><?php endif; ?>
    <?php if($error_pass_msg): ?><div class="bg-red-100 text-red-800 p-3 rounded-lg text-sm flex items-center gap-2"><i class="fa-solid fa-circle-exclamation"></i> Erro ao alterar a senha. Verifique sua senha atual.</div><?php endif; ?>
    
    <div x-data="{ photoName: null, photoPreview: '<?php echo htmlspecialchars($CURRENT_USER['foto_perfil']); ?>' }" class="bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 mb-6">Meu Perfil</h2>
        <form action="index.php" method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="action" value="update_profile">
             <div class="flex items-center gap-4">
                <img :src="photoPreview" alt="Preview" class="w-16 h-16 rounded-full bg-slate-200 object-cover border-2 border-slate-100">
                <div class="flex-1">
                    <label for="foto_perfil" class="cursor-pointer text-sm font-bold text-indigo-600 p-3 bg-indigo-50 rounded-lg hover:bg-indigo-100 transition-colors inline-block">
                        <i class="fa-solid fa-upload mr-2"></i> Trocar Foto
                    </label>
                    <input type="file" name="foto_perfil" id="foto_perfil" class="hidden" @change="photoName = $event.target.files[0].name; const reader = new FileReader(); reader.onload = (e) => { photoPreview = e.target.result }; reader.readAsDataURL($event.target.files[0]);">
                    <p x-show="photoName" x-text="photoName" class="text-xs text-slate-500 mt-2"></p>
                </div>
            </div>

            <div>
                <label for="nome" class="text-sm font-medium text-slate-600">Nome Completo</label>
                <input type="text" name="nome" id="nome" value="<?php echo htmlspecialchars($CURRENT_USER['nome']); ?>" class="w-full mt-1 p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" required>
            </div>
             <div>
                <label for="email" class="text-sm font-medium text-slate-600">E-mail</label>
                <input type="email" id="email" value="<?php echo htmlspecialchars($CURRENT_USER['email']); ?>" class="w-full mt-1 p-3 border border-slate-300 rounded-lg bg-slate-100 text-slate-500 cursor-not-allowed" disabled>
            </div>
            
            <!-- PRIVACIDADE / MODO RESTRITO -->
            <div class="bg-indigo-50 border border-indigo-100 p-4 rounded-xl">
                <div class="flex items-center justify-between">
                    <div>
                        <h4 class="font-bold text-indigo-900 flex items-center gap-2"><i class="fa-solid fa-user-secret"></i> Modo Restrito (Sócio Oculto)</h4>
                        <p class="text-xs text-indigo-700 mt-1 max-w-sm">Quando ativo, seus lançamentos pessoais e saldos bancários ficam ocultos para outros membros da família.</p>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="is_restricted" value="1" class="sr-only peer" <?php echo ($CURRENT_USER['is_restricted'] == 1) ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-indigo-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                </div>
            </div>

            <!-- CONFIGURAÇÃO DE RENDA AUTOMÁTICA -->
            <div class="border-t border-slate-100 pt-6">
                <h3 class="font-bold text-slate-800 mb-4 flex items-center gap-2"><i class="fa-solid fa-robot text-emerald-500"></i> Automação de Renda</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                     <div>
                        <label for="renda_estimada" class="text-sm font-medium text-slate-600">Salário / Renda Mensal</label>
                        <input type="text" oninput="formatMoney(this)" name="renda_estimada" id="renda_estimada" value="<?php echo $CURRENT_USER['renda_estimada'] ? number_format($CURRENT_USER['renda_estimada'], 2, ',', '.') : ''; ?>" class="w-full mt-1 p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none">
                    </div>
                    <div>
                        <label for="dia_recebimento_renda" class="text-sm font-medium text-slate-600">Dia do Pagamento</label>
                        <select name="dia_recebimento_renda" id="dia_recebimento_renda" class="w-full mt-1 p-3 border border-slate-300 rounded-lg bg-white focus:ring-2 focus:ring-indigo-500 outline-none">
                            <option value="">Manual</option>
                            <option value="101" <?php echo ($CURRENT_USER['dia_recebimento_renda'] == 101) ? 'selected' : ''; ?>>5º Dia Útil (Aprox. dia 5)</option>
                            <option value="102" <?php echo ($CURRENT_USER['dia_recebimento_renda'] == 102) ? 'selected' : ''; ?>>Último Dia do Mês</option>
                            <?php for($i=1; $i<=31; $i++): ?>
                                <option value="<?php echo $i; ?>" <?php echo ($CURRENT_USER['dia_recebimento_renda'] == $i) ? 'selected' : ''; ?>>Dia <?php echo $i; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label for="conta_recebimento_id" class="text-sm font-medium text-slate-600">Receber na Conta</label>
                        <select name="conta_recebimento_id" id="conta_recebimento_id" class="w-full mt-1 p-3 border border-slate-300 rounded-lg bg-white focus:ring-2 focus:ring-indigo-500 outline-none">
                            <option value="">Nenhuma (Dinheiro)</option>
                            <?php foreach($accounts as $acc): ?>
                                <option value="<?php echo $acc['id']; ?>" <?php echo ($CURRENT_USER['conta_recebimento_id'] == $acc['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($acc['nome_banco']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="text-xs text-slate-400 mt-2">Ao salvar, o sistema criará lançamentos futuros de salário para os próximos 2 anos. No dia do pagamento, o valor cairá automaticamente na conta escolhida ao fazer login.</p>
            </div>

             <div class="pt-4"><button type="submit" class="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-600/20">SALVAR PERFIL</button></div>
        </form>
    </div>
    
    <div class="bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 mb-6">Alterar Senha</h2>
        <form action="index.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="change_password">
            <div><label class="text-sm font-medium text-slate-600">Senha Atual</label><input type="password" name="senha_atual" class="w-full mt-1 p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" required></div>
            <div><label class="text-sm font-medium text-slate-600">Nova Senha</label><input type="password" name="nova_senha" class="w-full mt-1 p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" required></div>
            <div><label class="text-sm font-medium text-slate-600">Confirmar Nova Senha</label><input type="password" name="confirmar_nova_senha" class="w-full mt-1 p-3 border border-slate-300 rounded-lg focus:ring-2 focus:ring-indigo-500 outline-none" required></div>
            <div class="pt-4"><button type="submit" class="w-full py-3 bg-slate-800 text-white rounded-xl font-bold hover:bg-slate-900 transition-all">ALTERAR SENHA</button></div>
        </form>
    </div>

    <?php if ($CURRENT_USER['tipo_perfil'] === 'GUARDIÃO'): ?>
    <div class="bg-white p-8 rounded-2xl shadow-sm border border-slate-200">
        <h2 class="text-xl font-bold text-slate-800 mb-6">Configurações da Dinastia</h2>
        <form action="index.php" method="POST" class="space-y-4">
            <input type="hidden" name="action" value="update_family_settings">
            <div>
                <label for="moeda" class="text-sm font-medium text-slate-600">Moeda Padrão</label>
                <select name="moeda" id="moeda" class="w-full mt-1 p-3 border border-slate-300 rounded-lg">
                    <option value="BRL" <?php echo ($CURRENT_USER['moeda'] === 'BRL') ? 'selected' : ''; ?>>Real Brasileiro (BRL)</option>
                    <option value="USD" <?php echo ($CURRENT_USER['moeda'] === 'USD') ? 'selected' : ''; ?>>Dólar Americano (USD)</option>
                    <option value="EUR" <?php echo ($CURRENT_USER['moeda'] === 'EUR') ? 'selected' : ''; ?>>Euro (EUR)</option>
                </select>
            </div>
            <div>
                <label for="idioma" class="text-sm font-medium text-slate-600">Idioma</label>
                <select name="idioma" id="idioma" class="w-full mt-1 p-3 border border-slate-300 rounded-lg">
                    <option value="pt-BR" <?php echo ($CURRENT_USER['idioma'] === 'pt-BR') ? 'selected' : ''; ?>>Português (Brasil)</option>
                    <option value="en-US" <?php echo ($CURRENT_USER['idioma'] === 'en-US') ? 'selected' : ''; ?>>English (US)</option>
                </select>
            </div>
             <div class="pt-4"><button type="submit" class="w-full py-3 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all shadow-lg shadow-indigo-600/20">SALVAR CONFIGURAÇÕES</button></div>
        </form>
    </div>
    <?php endif; ?>
</div>