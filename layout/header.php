
<?php
// Inicializa variáveis para evitar erros em páginas públicas
$notifications = [];
$unread_count = 0;
$user_language = 'pt-BR'; // Idioma padrão

// Garante que a função de tradução esteja disponível
if (!function_exists('_t')) {
    require_once 'helpers.php';
}

// Verifica se o usuário está logado (variáveis definidas em db.php)
if (isset($LOGGED_IN_USER_ID) && isset($CURRENT_USER)) {
    $user_language = $CURRENT_USER['idioma'] ?? 'pt-BR';
    
    // Busca notificações não lidas
    $stmtNotif = $pdo->prepare("SELECT * FROM notificacoes WHERE usuario_id = ? AND lida = FALSE ORDER BY created_at DESC LIMIT 5");
    $stmtNotif->execute([$LOGGED_IN_USER_ID]);
    $notifications = $stmtNotif->fetchAll();
    $unread_count = count($notifications);
}
?>
<!DOCTYPE html>
<html lang="<?php echo substr($user_language, 0, 2); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nobl$ - <?php echo _t('app_subtitle'); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes slide-in { from { transform: translateX(100%); } to { transform: translateX(0); } }
        .animate-slide-in { animation: slide-in 0.3s ease-out forwards; }
        @keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in { animation: fade-in 0.5s ease-out forwards; }
        [x-cloak] { display: none !important; }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { bg: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
    </style>
     <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
     <script>
        // Função Global de Formatação de Dinheiro (BRL)
        window.formatMoney = function(el) {
            let value = el.value.replace(/\D/g, '');
            if(value === '') { el.value = ''; return; }
            value = (parseInt(value) / 100).toFixed(2) + '';
            value = value.replace('.', ',');
            value = value.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
            el.value = value;
            el.dispatchEvent(new Event('input')); // Garante que frameworks reativos detectem a mudança
        }
     </script>
</head>
<body class="bg-slate-50 text-slate-900" x-data="{ sidebarOpen: false }">