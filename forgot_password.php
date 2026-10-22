
<?php
session_start();
require_once 'db_auth.php';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';

    // Mensagem genérica padrão: nunca revela se o e-mail existe nem expõe o link.
    $message = "Se um usuário com este e-mail existir, um link de redefinição foi enviado para a caixa de entrada.";

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Por favor, insira um e-mail válido.';
        $message = '';
    } else {
        // --- RATE LIMITING: no máximo 3 solicitações por e-mail na última hora ---
        $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM password_resets WHERE email = ? AND created_at >= NOW() - INTERVAL 1 HOUR");
        $stmtCount->execute([$email]);
        $recentRequests = (int)$stmtCount->fetchColumn();

        if ($recentRequests >= 3) {
            // Mantém a mensagem genérica para não revelar informação, apenas evita gerar mais tokens.
            error_log("Rate limit de reset de senha atingido para: $email");
        } else {
            $stmt = $pdo->prepare("SELECT id FROM usuarios WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                $token = bin2hex(random_bytes(50));
                $stmt = $pdo->prepare("INSERT INTO password_resets (email, token, used) VALUES (?, ?, 0)");
                $stmt->execute([$email, $token]);

                $resetLink = "https://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password.php?token=" . $token;

                // --- ENVIO DE E-MAIL ---
                // O link JAMAIS deve ser exibido na tela. Enviado exclusivamente por e-mail.
                $subject = "Redefinição de senha - Nobl$";
                $body = "Olá,\n\nRecebemos uma solicitação para redefinir a senha da sua conta Nobl\$.\n"
                      . "Para criar uma nova senha, acesse o link abaixo (válido por 1 hora):\n\n"
                      . $resetLink . "\n\n"
                      . "Se você não solicitou esta redefinição, ignore este e-mail.\n";
                $headers = "From: no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'nobl.app') . "\r\n";

                // Em produção, substitua por um serviço de e-mail transacional (SMTP/API).
                @mail($email, $subject, $body, $headers);
            }
            // Em ambos os casos (usuário existindo ou não), a resposta é a mesma mensagem genérica.
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
            <h2 class="text-xl font-bold text-slate-800 text-center mb-1">Redefinir Senha</h2>
            <p class="text-sm text-slate-500 text-center mb-6">Insira seu e-mail para receber o link de redefinição.</p>
            
            <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg relative mb-4 text-xs" role="alert">
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            <?php if ($message): ?>
                <div class="bg-emerald-100 border border-emerald-400 text-emerald-700 px-4 py-3 rounded-lg relative mb-4 text-xs" role="alert">
                    <span><?php echo $message; ?></span>
                </div>
            <?php endif; ?>

            <?php if (!$message): ?>
            <form method="POST" action="forgot_password.php" class="space-y-4">
                <div>
                    <label for="email" class="text-xs font-bold text-slate-600">E-mail</label>
                    <input type="email" name="email" id="email" class="w-full p-3 mt-1 border border-slate-300 rounded-lg" required>
                </div>
                <button type="submit" class="w-full py-4 bg-indigo-600 text-white rounded-xl font-bold hover:bg-indigo-700 transition-all active:scale-95">
                    ENVIAR LINK
                </button>
            </form>
            <?php endif; ?>
        </div>
        <div class="text-center mt-6">
            <p class="text-sm text-slate-400">Lembrou a senha? <a href="login.php" class="font-bold text-indigo-400 hover:underline">Faça login</a>.</p>
        </div>
    </div>
</div>
<?php include 'layout/footer.php'; ?>
