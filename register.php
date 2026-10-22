
<?php
session_start();
require_once 'db_auth.php'; // Conexão para registro

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'register') {
    $nome_familia = $_POST['nome_familia'];
    $nome_guardiao = $_POST['nome_guardiao'];
    $email_guardiao = $_POST['email_guardiao'];
    $senha_guardiao = $_POST['senha_guardiao'];

    if (empty($nome_familia) || empty($nome_guardiao) || empty($email_guardiao) || empty($senha_guardiao)) {
        header('Location: register.php?error=empty'); exit;
    }
    if (strlen($senha_guardiao) < 8) {
        header('Location: register.php?error=weakpassword'); exit;
    }

    $pdo->beginTransaction();
    try {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $pdo->prepare("INSERT INTO familias (nome_familia, termos_aceite_data, termos_aceite_ip) VALUES (?, NOW(), ?)");
        $stmt->execute([$nome_familia, $ip]);
        $familia_id = $pdo->lastInsertId();

        $senha_hash = password_hash($senha_guardiao, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (familia_id, nome, email, senha_hash, tipo_perfil, ip_cadastro) VALUES (?, ?, ?, ?, 'GUARDIÃO', ?)");
        $stmt->execute([$familia_id, $nome_guardiao, $email_guardiao, $senha_hash, $ip]);
        $new_user_id = $pdo->lastInsertId();
        
        // Categorias Padrão Revisadas e Expandidas
        $categorias_padrao = [
            // Receitas
            ['nome' => 'Salário/Renda', 'tipo' => 'RECEITA', 'grupo' => 'Receitas', 'icone' => 'fa-sack-dollar'],
            ['nome' => 'Investimentos', 'tipo' => 'RECEITA', 'grupo' => 'Receitas', 'icone' => 'fa-chart-line'],
            
            // Essenciais
            ['nome' => 'Aluguel/Condomínio', 'tipo' => 'DESPESA', 'grupo' => 'Essenciais', 'icone' => 'fa-house'],
            ['nome' => 'Mercado', 'tipo' => 'DESPESA', 'grupo' => 'Essenciais', 'icone' => 'fa-cart-shopping'],
            ['nome' => 'Luz/Água/Net', 'tipo' => 'DESPESA', 'grupo' => 'Essenciais', 'icone' => 'fa-bolt'],
            ['nome' => 'Educação', 'tipo' => 'DESPESA', 'grupo' => 'Essenciais', 'icone' => 'fa-graduation-cap'],
            ['nome' => 'Saúde/Farmácia', 'tipo' => 'DESPESA', 'grupo' => 'Essenciais', 'icone' => 'fa-notes-medical'],

            // Veículos (Detalhamento)
            ['nome' => 'Combustível', 'tipo' => 'DESPESA', 'grupo' => 'Veículos', 'icone' => 'fa-gas-pump'],
            ['nome' => 'Manutenção/Mecânica', 'tipo' => 'DESPESA', 'grupo' => 'Veículos', 'icone' => 'fa-wrench'],
            ['nome' => 'Seguro Auto', 'tipo' => 'DESPESA', 'grupo' => 'Veículos', 'icone' => 'fa-shield-car'],
            ['nome' => 'Impostos (IPVA)', 'tipo' => 'DESPESA', 'grupo' => 'Veículos', 'icone' => 'fa-file-invoice-dollar'],
            ['nome' => 'Estacionamento/Pedágio', 'tipo' => 'DESPESA', 'grupo' => 'Veículos', 'icone' => 'fa-parking'],
            ['nome' => 'Limpeza/Lava-jato', 'tipo' => 'DESPESA', 'grupo' => 'Veículos', 'icone' => 'fa-soap'],
            ['nome' => 'Multas', 'tipo' => 'DESPESA', 'grupo' => 'Veículos', 'icone' => 'fa-triangle-exclamation'],

            // Pets (Detalhamento)
            ['nome' => 'Alimentação Pet', 'tipo' => 'DESPESA', 'grupo' => 'Pets', 'icone' => 'fa-bone'],
            ['nome' => 'Veterinário', 'tipo' => 'DESPESA', 'grupo' => 'Pets', 'icone' => 'fa-user-doctor'],
            ['nome' => 'Vacinas/Medicamentos', 'tipo' => 'DESPESA', 'grupo' => 'Pets', 'icone' => 'fa-syringe'],
            ['nome' => 'Banho e Tosa', 'tipo' => 'DESPESA', 'grupo' => 'Pets', 'icone' => 'fa-scissors'],
            ['nome' => 'Acessórios/Brinquedos', 'tipo' => 'DESPESA', 'grupo' => 'Pets', 'icone' => 'fa-baseball'],
            ['nome' => 'Hotel/Creche', 'tipo' => 'DESPESA', 'grupo' => 'Pets', 'icone' => 'fa-hotel'],

            // Estilo de Vida
            ['nome' => 'Lazer/Restaurantes', 'tipo' => 'DESPESA', 'grupo' => 'Estilo de Vida', 'icone' => 'fa-utensils'],
            ['nome' => 'Viagens', 'tipo' => 'DESPESA', 'grupo' => 'Estilo de Vida', 'icone' => 'fa-plane'],
            ['nome' => 'Compras/Pessoal', 'tipo' => 'DESPESA', 'grupo' => 'Estilo de Vida', 'icone' => 'fa-bag-shopping'],
            
            // Serviços (Anteriormente Assinaturas)
            ['nome' => 'Streaming', 'tipo' => 'DESPESA', 'grupo' => 'Serviços', 'icone' => 'fa-tv'],
            ['nome' => 'Serviços Digitais', 'tipo' => 'DESPESA', 'grupo' => 'Serviços', 'icone' => 'fa-cloud'],
        ];

        $stmtCat = $pdo->prepare("INSERT INTO categorias (familia_id, nome, tipo, grupo, icone) VALUES (?, ?, ?, ?, ?)");
        foreach($categorias_padrao as $cat) {
            $stmtCat->execute([$familia_id, $cat['nome'], $cat['tipo'], $cat['grupo'], $cat['icone']]);
        }

        $pdo->commit();
        
        session_regenerate_id(true);
        $_SESSION['user_id'] = $new_user_id;
        $_SESSION['familia_id'] = $familia_id;
        $_SESSION['user_nome'] = $nome_guardiao;
        $_SESSION['show_onboarding'] = true;

        header('Location: index.php');
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        header('Location: register.php?error=dberror');
        exit;
    }
}

include 'layout/header.php';
?>
<div x-data="{ showModal: false, modalTitle: '', modalContent: '' }" class="min-h-screen bg-slate-900 flex flex-col items-center justify-center p-4">
    <div class="max-w-xl w-full">
        <form method="POST" action="register.php" class="bg-white rounded-3xl p-8 md:p-12 shadow-2xl relative overflow-hidden flex flex-col">
            <input type="hidden" name="action" value="register">
            
            <div class="space-y-6">
                <div class="w-16 h-16 bg-indigo-600 rounded-2xl flex items-center justify-center text-white text-3xl mb-8"><i class="fa-solid fa-crown"></i></div>
                <h2 class="text-3xl font-extrabold text-slate-900">Crie sua Dinastia</h2>
                <p class="text-slate-500">Comece a jornada financeira da sua família.</p>
                <hr/>
                <div>
                    <label class="text-xs font-bold text-slate-600">Nome da Dinastia (Família)</label>
                    <input type="text" name="nome_familia" placeholder="Ex: Família Pendragon" class="w-full mt-1 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl font-medium focus:outline-none" required/>
                </div>
                <h3 class="font-bold pt-4 text-slate-800">Dados do Guardião (Admin)</h3>
                <div>
                    <label class="text-xs font-bold text-slate-600">Seu Nome Completo</label>
                    <input type="text" name="nome_guardiao" placeholder="Ex: Arthur Pendragon" class="w-full mt-1 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl font-medium focus:outline-none" required/>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600">Seu E-mail</label>
                    <input type="email" name="email_guardiao" placeholder="Seu melhor e-mail" class="w-full mt-1 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl font-medium focus:outline-none" required/>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-600">Crie uma Senha</label>
                    <input type="password" name="senha_guardiao" id="senha_guardiao" class="w-full mt-1 px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl font-medium focus:outline-none" required/>
                </div>
                <div class="flex items-start gap-3 pt-4">
                    <input type="checkbox" id="termos" name="termos" required class="h-5 w-5 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 mt-0.5">
                    <label for="termos" class="text-sm text-slate-600">
                        Eu aceito os 
                        <button type="button" @click="showModal = true; modalTitle = 'Termos de Serviço'; modalContent = document.getElementById('terms-content').innerHTML" class="font-bold text-indigo-600 hover:underline">Termos de Serviço</button> 
                        e a 
                        <button type="button" @click="showModal = true; modalTitle = 'Política de Privacidade'; modalContent = document.getElementById('privacy-content').innerHTML" class="font-bold text-indigo-600 hover:underline">Política de Privacidade</button>.
                    </label>
                </div>
            </div>
            <button type="submit" class="w-full mt-12 py-5 bg-indigo-600 text-white rounded-2xl font-black text-lg tracking-wide shadow-xl shadow-indigo-600/30 hover:bg-indigo-700 transition-all relative z-10 active:scale-95">
                FUNDAR DINASTIA
            </button>
        </form>
         <div class="text-center mt-6">
            <p class="text-sm text-slate-400">Já tem uma dinastia? <a href="login.php" class="font-bold text-indigo-400 hover:underline">Faça login</a>.</p>
        </div>
    </div>
    
    <!-- Modal -->
    <div x-show="showModal" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div @click.away="showModal = false" class="bg-white rounded-2xl p-6 md:p-8 w-full max-w-3xl max-h-[90vh] flex flex-col">
            <h2 class="text-2xl font-bold mb-4" x-text="modalTitle"></h2>
            <div class="prose max-w-none overflow-y-auto" x-html="modalContent"></div>
            <button @click="showModal = false" class="mt-6 w-full py-3 bg-slate-200 rounded-lg font-bold">Fechar</button>
        </div>
    </div>

    <!-- Conteúdo dos Modais (escondido) -->
    <template id="terms-content">
        <div>
            <h4>1. Contas de Usuário</h4>
            <p>Você é responsável por manter a confidencialidade de sua conta e senha...</p>
            <h4>2. Uso Aceitável</h4>
            <p>Você concorda em não usar o serviço para qualquer finalidade ilegal ou proibida...</p>
        </div>
    </template>
    <template id="privacy-content">
        <div>
            <h4>1. Informações que Coletamos</h4>
            <p>Coletamos informações que você nos fornece diretamente, como dados de cadastro e financeiros...</p>
            <h4>2. Como Usamos Suas Informações</h4>
            <p>Utilizamos suas informações para fornecer, operar e manter nossos serviços...</p>
        </div>
    </template>
</div>
<?php include 'layout/footer.php'; ?>
