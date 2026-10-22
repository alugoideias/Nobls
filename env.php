<?php
/**
 * Carrega variáveis de ambiente de um arquivo .env (se existir).
 * No Railway/produção as variáveis já chegam pelo ambiente, então o .env é opcional.
 * Inclua este arquivo antes de usar getenv() para credenciais.
 */
(function () {
    $envPath = __DIR__ . '/.env';
    if (!is_readable($envPath)) {
        return;
    }
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
            $value = substr($value, 1, -1);
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
})();
