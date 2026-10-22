<?php
session_start();
require_once 'db_auth.php'; // Usa uma conexão separada para não vazar dados
$error = '';
$success = '';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

if(isset($_GET['success']) && $_GET['success'] === 'reset') {
    $success = 'Sua senha foi redefinida com sucesso! Você já pode fazer login.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';

    if (empty($email) || empty($senha)) {
        $error = 'Por favor, preencha e-mail e senha.';
    } else {
        $stmt = $pdo->prepare("SELECT id, familia_id, nome, tipo_perfil, senha_hash, email FROM usuarios WHERE email = ? AND deleted_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($senha, $user['senha_hash'])) {
            // Regenera o ID da sessão para segurança
            session_regenerate_id(true);
            
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['familia_id'] = $user['familia_id'];
            $_SESSION['user_nome'] = $user['nome'];
            $_SESSION['user_perfil'] = $user['tipo_perfil'];
            
            // Elegibilidade a Super Admin é decidida UMA ÚNICA VEZ aqui, no login,
            // e persistida na sessão. Os demais arquivos usam essas flags em vez de
            // comparar o e-mail repetidamente (evita privilégio por string hardcoded).
            $isAdminAccount = ($user['familia_id'] == 0 || $user['email'] === 'admin@nobl.app');
            $_SESSION['is_admin_account'] = $isAdminAccount;
            if ($isAdminAccount) {
                 $_SESSION['is_super_admin'] = true;
            }

            // --- ROBÔ DE SALÁRIO / RENDA AUTOMÁTICA ---
            // Verifica receitas pendentes do usuário que já venceram (data <= hoje)
            // CORREÇÃO: Filtra especificamente pela descrição automática e exclui transferências internas
            $today = date('Y-m-d');
            $salaryProcessed = 0;
            $countProcessed = 0;

            try {
                // Adicionado: AND descricao = 'Salário Mensal (Automático)' AND is_internal_transfer = 0
                $stmtSal = $pdo->prepare("SELECT id, valor, conta_id, descricao FROM lancamentos WHERE usuario_id = ? AND tipo = 'RECEITA' AND status = 'PENDENTE' AND data_competencia <= ? AND descricao = 'Salário Mensal (Automático)' AND is_internal_transfer = 0 AND deleted_at IS NULL");
                $stmtSal->execute([$user['id'], $today]);
                $pendingSalaries = $stmtSal->fetchAll();

                if ($pendingSalaries) {
                    foreach ($pendingSalaries as $sal) {
                        $targetAccount = $sal['conta_id'];

                        // FALLBACK INTELIGENTE: Se o lançamento não tem conta, busca a conta padrão do usuário
                        if (empty($targetAccount)) {
                            $stmtUserAcc = $pdo->prepare("SELECT conta_recebimento_id FROM usuarios WHERE id = ?");
                            $stmtUserAcc->execute([$user['id']]);
                            $targetAccount = $stmtUserAcc->fetchColumn();
                            
                            // Se encontrou uma conta padrão, atualiza o lançamento para ficar vinculado
                            if ($targetAccount) {
                                $pdo->prepare("UPDATE lancamentos SET conta_id = ? WHERE id = ?")->execute([$targetAccount, $sal['id']]);
                            }
                        }

                        // 1. Marca como PAGO
                        $pdo->prepare("UPDATE lancamentos SET status = 'PAGO' WHERE id = ?")->execute([$sal['id']]);

                        // 2. Atualiza Saldo da Conta (Se houver conta vinculada)
                        if (!empty($targetAccount)) {
                            $pdo->prepare("UPDATE contas_bancarias SET saldo_atual = saldo_atual + ? WHERE id = ?")->execute([(float)$sal['valor'], $targetAccount]);
                        }
                        
                        $salaryProcessed += $sal['valor'];
                        $countProcessed++;
                    }
                }
            } catch (Exception $e) {
                // Não impede o login, mas registra o erro para diagnóstico.
                error_log('Robô de salário falhou para o usuário ' . $user['id'] . ': ' . $e->getMessage());
            }

            $redirectUrl = 'index.php';
            if ($countProcessed > 0) {
                $redirectUrl .= '?salary_processed=' . urlencode($salaryProcessed);
            }

            header("Location: $redirectUrl");
            exit;
        } else {
            $error = 'E-mail ou senha inválidos.';
        }
    }
}

include 'layout/header.php';
?>
<div class="min-h-screen bg-slate-900 flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <div class="flex justify-center items-center gap-3 mb-8">
            <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white font-bold text-2xl shadow-lg shadow-indigo-500/30">
                N$
            </div>
            <div>
                <h1 class="text-3xl font-bold text-white tracking-tight">Nobl$</h1>
                <p class="text-[10px] uppercase tracking-widest text-indigo-400 font-bold">Dynasty Finance</p>
            </div>
        </div>
        
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-xl font-bold text-slate-800 text-center mb-1">Bem-vindo de volta!</h2>
            <p class="text-sm text-slate-500 text-center mb-6">Acesse sua dinastia.</p>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4 text-xs" role="alert">
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="bg-emerald-100 border border-emerald-400 text-emerald-700 px-4 py-3 rounded-lg relative mb-4 text-xs" role="alert">
                    <span><?php echo htmlspecialchars($success); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label for="email" class="text-xs font-bold text-slate-600">E-mail</label>
                    <input type="email" name="email" id="email" class="w-full p-3 mt-1 border border-slate-300 rounded-lg" required>
                </div>
                <div>
                     <div class="flex justify-between items-center">
                        <label for="senha" class="text-xs font-bold text-slate-600">Senha</label>
                        <a href="forgot_password.php" class="text-xs font-bold text-indigo-600 hover:underline">Esqueceu a senha?</a>
                    </div>
                    <input type="password" name="senha" id="senha" class="w-full p-3 mt-1 border border-slate-300 rounded-lg" required>
                </div>
                <button type="submit" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all active:scale-95">
                    ENTRAR
                </button>
            </form>
        </div>
        <div class="text-center mt-6">
            <p class="text-sm text-slate-400">Não tem uma dinastia? <a href="register.php" class="font-bold text-indigo-400 hover:underline">Crie uma agora</a>.</p>
        </div>
    </div>
</div>
<?php include 'layout/footer.php'; ?>