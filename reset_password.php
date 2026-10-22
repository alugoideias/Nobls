
<?php
session_start();
require_once 'db_auth.php';
$error = '';
$message = '';
$token = $_GET['token'] ?? '';
$validToken = false;

if ($token) {
    // Verifica se o token é válido, não expirou (1 hora) e ainda não foi usado.
    $stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND used = 0 AND created_at >= NOW() - INTERVAL 1 HOUR");
    $stmt->execute([$token]);
    $resetRequest = $stmt->fetch();
    if ($resetRequest) {
        $validToken = true;
    } else {
        $error = "Token inválido ou expirado. Por favor, solicite um novo link de redefinição.";
    }
} else {
    $error = "Nenhum token fornecido.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    verify_csrf();
    $senha = $_POST['senha'];
    $senha_confirm = $_POST['senha_confirm'];

    if ($senha !== $senha_confirm) {
        $error = "As senhas não coincidem.";
    } elseif (strlen($senha) < 8) {
        $error = "A senha deve ter no mínimo 8 caracteres.";
    } else {
        $email = $resetRequest['email'];
        $senha_hash = password_hash($senha, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            // Atualiza a senha do usuário
            $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE email = ?");
            $stmt->execute([$senha_hash, $email]);

            // Invalida TODOS os tokens do e-mail (marca como usados) para evitar reutilização
            $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ?");
            $stmt->execute([$email]);

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Não foi possível redefinir a senha. Tente novamente.";
        }

        if (!$error) {
            // Redireciona para o login com mensagem de sucesso
            header('Location: login.php?success=reset');
            exit;
        }
    }
}

include 'layout/header.php';
?>
<div class="min-h-screen bg-slate-900 flex flex-col items-center justify-center p-4">
    <div class="w-full max-w-sm">
        <div class="flex justify-center items-center gap-3 mb-8">
            <div class="w-12 h-12 bg-indigo-600 rounded-2xl flex items-center justify-center text-white font-bold text-2xl shadow-lg shadow-indigo-500/30">N$</div>
        </div>
        
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-xl font-bold text-slate-800 text-center mb-1">Crie sua Nova Senha</h2>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4 text-xs" role="alert">
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <?php if ($validToken): ?>
            <form method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" class="space-y-4">
                <?php echo csrf_field(); ?>
                <div>
                    <label for="senha" class="text-xs font-bold text-slate-600">Nova Senha</label>
                    <input type="password" name="senha" id="senha" class="w-full p-3 mt-1 border border-slate-300 rounded-lg" required>
                </div>
                 <div>
                    <label for="senha_confirm" class="text-xs font-bold text-slate-600">Confirme a Nova Senha</label>
                    <input type="password" name="senha_confirm" id="senha_confirm" class="w-full p-3 mt-1 border border-slate-300 rounded-lg" required>
                </div>
                <button type="submit" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all active:scale-95">
                    REDEFINIR SENHA
                </button>
            </form>
            <?php else: ?>
                 <div class="text-center">
                    <a href="forgot_password.php" class="font-bold text-indigo-600 hover:underline">Solicitar novo link</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php include 'layout/footer.php'; ?>
