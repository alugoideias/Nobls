
<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!function_exists('format_currency')) {
    /**
     * Formats a number as currency based on the user's locale.
     * @param float $value The number to format.
     * @param string $currency The currency code (e.g., 'BRL', 'USD', 'EUR').
     * @return string The formatted currency string.
     */
    function format_currency($value, $currency = 'BRL') {
        // Usa o idioma salvo na sessão do usuário, que foi definido em db.php
        $locale = $_SESSION['user_lang'] ?? 'pt_BR';

        // Garante que o valor seja float
        $floatValue = floatval($value);

        // Cria o formatador com a localidade do usuário
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        
        // Formata a moeda
        return $formatter->formatCurrency($floatValue, $currency);
    }
}

if (!function_exists('_t')) {
    /**
     * A simple translation function.
     * @param string $key The translation key.
     * @return string The translated string or the key itself.
     */
    function _t($key) {
        $lang = $_SESSION['user_lang'] ?? 'pt-BR';
        
        $translations = [
            'pt-BR' => [
                'app_subtitle' => 'Gestão de Dinastias Familiares',
                'header_welcome' => 'Bem-vindo(a)',
                'sidebar_dashboard' => 'Dashboard',
                'sidebar_transactions' => 'Lançamentos',
                'sidebar_budget' => 'Orçamento',
                'sidebar_reports' => 'Relatórios',
                'sidebar_assets' => 'Patrimônio',
                'sidebar_goals' => 'Metas',
                'sidebar_gamification' => 'Jornada',
                'sidebar_import' => 'Importar',
            ],
            'en-US' => [
                'app_subtitle' => 'Family Dynasty Management',
                'header_welcome' => 'Welcome',
                'sidebar_dashboard' => 'Dashboard',
                'sidebar_transactions' => 'Transactions',
                'sidebar_budget' => 'Budget',
                'sidebar_reports' => 'Reports',
                'sidebar_assets' => 'Assets',
                'sidebar_goals' => 'Goals',
                'sidebar_gamification' => 'Journey',
                'sidebar_import' => 'Import',
            ]
        ];
        
        return $translations[$lang][$key] ?? str_replace('_', ' ', ucfirst($key));
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Retorna o token CSRF da sessão, gerando um novo se ainda não existir.
     * @return string Token CSRF em hexadecimal.
     */
    function csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Renderiza um input hidden com o token CSRF para uso em formulários.
     * @return string HTML do campo oculto.
     */
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('verify_csrf')) {
    /**
     * Valida o token CSRF enviado no POST contra o da sessão.
     * Em caso de falha, encerra a requisição com HTTP 403.
     */
    function verify_csrf() {
        // Aceita o token tanto via campo de formulário quanto via header HTTP (AJAX).
        $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $session = $_SESSION['csrf_token'] ?? '';
        if (empty($sent) || empty($session) || !hash_equals($session, $sent)) {
            http_response_code(403);
            die('Falha na validação de segurança (CSRF). Recarregue a página e tente novamente.');
        }
    }
}

if (!function_exists('is_super_admin')) {
    /**
     * Verificação centralizada de super administrador.
     * Baseia-se na flag persistida na sessão, definida no login.
     * @return bool
     */
    function is_super_admin() {
        return !empty($_SESSION['is_super_admin']);
    }
}

if (!function_exists('reconcile_account_balances')) {
    /**
     * Recalcula o saldo_atual de todas as contas de uma família a partir do
     * saldo_inicial somado aos lançamentos PAGOS (receitas somam, despesas subtraem).
     * Serve como rede de segurança contra divergências do saldo materializado.
     *
     * @param PDO $pdo        Conexão ativa.
     * @param int $familiaId  Família cujas contas serão reconciliadas.
     * @return int            Número de contas reconciliadas.
     */
    function reconcile_account_balances(PDO $pdo, $familiaId) {
        $stmt = $pdo->prepare("
            UPDATE contas_bancarias c
            LEFT JOIN (
                SELECT conta_id,
                       SUM(CASE WHEN tipo = 'RECEITA' THEN valor ELSE -valor END) AS mov
                FROM lancamentos
                WHERE status = 'PAGO' AND conta_id IS NOT NULL AND deleted_at IS NULL
                GROUP BY conta_id
            ) m ON m.conta_id = c.id
            SET c.saldo_atual = c.saldo_inicial + COALESCE(m.mov, 0)
            WHERE c.familia_id = ? AND c.deleted_at IS NULL
        ");
        $stmt->execute([$familiaId]);
        return $stmt->rowCount();
    }
}
