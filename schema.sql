
-- Versão 2.3.0 - Schema com Orçamento, Relatórios e Notificações
-- Baseado na Documentação Técnica Mestra

-- Drop tables if they exist to start fresh
DROP TABLE IF EXISTS `pet_vacinacoes`, `pets`, `lancamento_comentarios`, `orcamentos`, `meta_aportes`, `ativos_veiculos`, `ativos_residencias`, `usuario_conquistas`, `conquistas`, `password_resets`, `audit_logs`, `notificacoes`, `lancamentos`, `metas`, `cartoes_credito`, `contas_bancarias`, `categorias`, `configuracoes`, `usuarios`, `familias`;

-- Tabela de Tenants
CREATE TABLE `familias` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome_familia` VARCHAR(255) NOT NULL,
  `plano` ENUM('FREE', 'PREMIUM') NOT NULL DEFAULT 'FREE',
  `status` ENUM('ATIVO', 'BLOQUEADO') NOT NULL DEFAULT 'ATIVO',
  `moeda` VARCHAR(10) NOT NULL DEFAULT 'BRL',
  `idioma` VARCHAR(10) NOT NULL DEFAULT 'pt-BR',
  `xp` INT NOT NULL DEFAULT 0,
  `nivel` INT NOT NULL DEFAULT 1,
  `termos_aceite_data` DATETIME NOT NULL,
  `termos_aceite_ip` VARCHAR(45) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Contas Bancárias
CREATE TABLE `contas_bancarias` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `usuario_id` INT NOT NULL, `nome_banco` VARCHAR(255) NOT NULL, `tipo` ENUM('CONTA_CORRENTE', 'POUPANCA', 'INVESTIMENTO') NOT NULL, `saldo_atual` DECIMAL(10, 2) NOT NULL DEFAULT 0.00, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Usuários (Membros das Famílias)
CREATE TABLE `usuarios` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `nome` VARCHAR(255) NOT NULL, `email` VARCHAR(255) NOT NULL UNIQUE, `senha_hash` VARCHAR(255) NOT NULL, `tipo_perfil` ENUM('GUARDIÃO', 'SÊNIOR', 'JOVEM') NOT NULL, `foto_perfil` VARCHAR(255) DEFAULT 'https://i.pravatar.cc/150', `renda_estimada` DECIMAL(10, 2) DEFAULT NULL, `dia_recebimento_renda` TINYINT DEFAULT NULL, `conta_recebimento_id` INT NULL, `tags_perfil` JSON DEFAULT NULL, `config_permissoes` JSON DEFAULT NULL, `termos_aceite` BOOLEAN DEFAULT TRUE, `termos_data` DATETIME DEFAULT CURRENT_TIMESTAMP, `ip_cadastro` VARCHAR(45) NOT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE, FOREIGN KEY (`conta_recebimento_id`) REFERENCES `contas_bancarias`(`id`) ON DELETE SET NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabelas Auxiliares
CREATE TABLE `password_resets` ( `email` VARCHAR(255) NOT NULL, `token` VARCHAR(255) NOT NULL, `used` BOOLEAN NOT NULL DEFAULT 0, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (`email`, `token`), KEY `idx_token` (`token`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `conquistas` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `nome` VARCHAR(255) NOT NULL, `descricao` TEXT NOT NULL, `icone` VARCHAR(50) NOT NULL, `pontos` INT NOT NULL DEFAULT 10 ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `usuario_conquistas` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `usuario_id` INT NOT NULL, `conquista_id` INT NOT NULL, `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY `user_achievement` (`usuario_id`, `conquista_id`), FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE, FOREIGN KEY (`conquista_id`) REFERENCES `conquistas`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `configuracoes` ( `chave` VARCHAR(50) PRIMARY KEY, `valor` TEXT NOT NULL, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Categorias
CREATE TABLE `categorias` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `nome` VARCHAR(255) NOT NULL, `icone` VARCHAR(50) DEFAULT 'fa-tag', `tipo` ENUM('RECEITA', 'DESPESA') NOT NULL, `grupo` VARCHAR(50) DEFAULT NULL, `parent_id` INT DEFAULT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabelas de Ativos
CREATE TABLE `ativos_veiculos` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `descricao` VARCHAR(255) NOT NULL, `marca` VARCHAR(100), `modelo` VARCHAR(100), `ano` INT, `valor_estimado` DECIMAL(10, 2), `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `ativos_residencias` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `descricao` VARCHAR(255) NOT NULL, `endereco` VARCHAR(255), `valor_estimado` DECIMAL(10, 2), `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Cartões de Crédito
CREATE TABLE `cartoes_credito` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `usuario_id` INT NOT NULL, `nome_cartao` VARCHAR(255) NOT NULL, `limite_total` DECIMAL(10, 2) NOT NULL, `dia_vencimento` TINYINT NOT NULL, `dia_fechamento` TINYINT NOT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE, FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Lançamentos
CREATE TABLE `lancamentos` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `usuario_id` INT NOT NULL, `criador_id` INT NOT NULL, `descricao` VARCHAR(255) NOT NULL, `valor` DECIMAL(10, 2) NOT NULL, `tipo` ENUM('RECEITA', 'DESPESA') NOT NULL, `data_competencia` DATE NOT NULL, `data_pagamento` DATE NULL, `status` ENUM('PENDENTE', 'PAGO', 'ATRASADO') NOT NULL DEFAULT 'PENDENTE', `categoria_id` INT, `conta_id` INT NULL, `cartao_id` INT NULL, `veiculo_id` INT NULL, `residencia_id` INT NULL, `tipo_recorrencia` ENUM('UNICA', 'SEMANAL', 'MENSAL', 'ANUAL') NOT NULL DEFAULT 'UNICA', `parcelado` BOOLEAN DEFAULT FALSE, `parcela_atual` TINYINT DEFAULT NULL, `parcela_total` TINYINT DEFAULT NULL, `recorrencia_grupo_id` VARCHAR(36) NULL, `comprovante_url` VARCHAR(255) NULL, `origem_lancamento` VARCHAR(50) DEFAULT 'MANUAL', `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE, FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`), FOREIGN KEY (`criador_id`) REFERENCES `usuarios`(`id`), FOREIGN KEY (`categoria_id`) REFERENCES `categorias`(`id`), FOREIGN KEY (`conta_id`) REFERENCES `contas_bancarias`(`id`), FOREIGN KEY (`cartao_id`) REFERENCES `cartoes_credito`(`id`), FOREIGN KEY (`veiculo_id`) REFERENCES `ativos_veiculos`(`id`) ON DELETE SET NULL, FOREIGN KEY (`residencia_id`) REFERENCES `ativos_residencias`(`id`) ON DELETE SET NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `lancamento_comentarios` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `lancamento_id` INT NOT NULL, `usuario_id` INT NOT NULL, `comentario` TEXT NOT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (`lancamento_id`) REFERENCES `lancamentos`(`id`) ON DELETE CASCADE, FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Metas e Orçamentos
CREATE TABLE `metas` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `nome` VARCHAR(255) NOT NULL, `valor_alvo` DECIMAL(10, 2) NOT NULL, `valor_atual` DECIMAL(10, 2) NOT NULL DEFAULT 0.00, `data_limite` DATE, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, `deleted_at` TIMESTAMP NULL DEFAULT NULL, FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `meta_aportes` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `meta_id` INT NOT NULL, `usuario_id` INT NOT NULL, `valor` DECIMAL(10, 2) NOT NULL, `data_aporte` DATE NOT NULL, `lancamento_id` INT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (`meta_id`) REFERENCES `metas`(`id`) ON DELETE CASCADE, FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`), FOREIGN KEY (`lancamento_id`) REFERENCES `lancamentos`(`id`) ON DELETE SET NULL ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `orcamentos` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `familia_id` INT NOT NULL, `categoria_id` INT NOT NULL, `valor_orcado` DECIMAL(10, 2) NOT NULL, `mes` TINYINT NOT NULL, `ano` SMALLINT NOT NULL, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY `familia_categoria_periodo` (`familia_id`, `categoria_id`, `mes`, `ano`), FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE, FOREIGN KEY (`categoria_id`) REFERENCES `categorias`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de Auditoria e Notificações
CREATE TABLE `audit_logs` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `usuario_id` INT NOT NULL, `acao` VARCHAR(255) NOT NULL, `dados_antes` JSON, `dados_depois` JSON, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE `notificacoes` ( `id` INT AUTO_INCREMENT PRIMARY KEY, `usuario_id` INT NOT NULL, `titulo` VARCHAR(255) NOT NULL, `mensagem` TEXT, `tipo` VARCHAR(50), `link` VARCHAR(255), `lida` BOOLEAN DEFAULT FALSE, `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP, FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- NOVAS TABELAS: MÓDULO PETS
CREATE TABLE `pets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `familia_id` INT NOT NULL,
  `nome` VARCHAR(255) NOT NULL,
  `raca` VARCHAR(100) DEFAULT NULL,
  `data_nascimento` DATE DEFAULT NULL,
  `peso` DECIMAL(5, 2) DEFAULT NULL,
  `foto` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`familia_id`) REFERENCES `familias`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE `pet_vacinacoes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `pet_id` INT NOT NULL,
  `nome_vacina` VARCHAR(255) NOT NULL,
  `data_aplicacao` DATE NOT NULL,
  `proxima_dose` DATE DEFAULT NULL,
  `custo` DECIMAL(10, 2) DEFAULT 0.00,
  `lancamento_id` INT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`pet_id`) REFERENCES `pets`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`lancamento_id`) REFERENCES `lancamentos`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- --- DADOS DE EXEMPLO (SEED) ---
INSERT INTO `configuracoes` (`chave`, `valor`) VALUES ('GEMINI_API_KEY', 'SUA_API_KEY_AQUI') ON DUplicate KEY UPDATE valor=VALUES(valor);
INSERT INTO `familias` (`id`, `nome_familia`, `plano`, `termos_aceite_data`, `termos_aceite_ip`) VALUES (1, 'Dinastia Pendragon', 'PREMIUM', NOW(), '127.0.0.1');
INSERT INTO `usuarios` (`id`, `familia_id`, `nome`, `email`, `senha_hash`, `tipo_perfil`, `ip_cadastro`) VALUES (1, 1, 'Arthur Pendragon', 'arthur@nobl.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'GUARDIÃO', '127.0.0.1');
SET FOREIGN_KEY_CHECKS=0;
INSERT INTO `usuarios` (`id`, `familia_id`, `nome`, `email`, `senha_hash`, `tipo_perfil`, `ip_cadastro`) VALUES (99, 0, 'Super Admin', 'admin@nobl.app', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'GUARDIÃO', '127.0.0.1');
SET FOREIGN_KEY_CHECKS=1;
