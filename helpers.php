
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