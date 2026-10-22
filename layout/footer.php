
<!-- Toast Notification Area -->
<div x-data="{ show: false, message: '', type: 'success' }" 
     x-init="<?php 
        $jsInit = "";
        if (isset($_GET['success'])) {
            $msg = isset($_GET['msg']) ? $_GET['msg'] : 'Operação realizada com sucesso!';
            $jsInit = "show = true; message = " . json_encode($msg) . "; type = 'success'; setTimeout(() => show = false, 5000);";
        } elseif (isset($_GET['error'])) {
            $msg = isset($_GET['msg']) ? $_GET['msg'] : 'Ocorreu um erro na operação.';
            $msg = urldecode($msg);
            $jsInit = "show = true; message = " . json_encode($msg) . "; type = 'error'; setTimeout(() => show = false, 8000);";
        } elseif (isset($_GET['salary_processed'])) {
            // Notificação Especial do Robô de Salário
            $val = format_currency($_GET['salary_processed']);
            $msg = "Bom dia! Seu salário/renda de $val caiu na conta automaticamente.";
            $jsInit = "show = true; message = " . json_encode($msg) . "; type = 'success'; setTimeout(() => show = false, 8000);";
        }
        echo htmlspecialchars($jsInit, ENT_QUOTES, 'UTF-8');
     ?>"
     x-show="show" 
     x-transition:enter="transition ease-out duration-300"
     x-transition:enter-start="opacity-0 translate-y-2"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-300"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 translate-y-2"
     x-cloak
     class="fixed bottom-4 right-4 z-50 flex items-center gap-3 px-6 py-4 rounded-xl shadow-2xl border border-white/20 min-w-[300px] max-w-md"
     :class="type === 'success' ? 'bg-slate-800 text-white' : 'bg-red-600 text-white'">
    
    <div class="text-2xl">
        <i class="fa-solid" :class="type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'"></i>
    </div>
    <div class="flex-1">
        <h4 class="font-bold text-sm" x-text="type === 'success' ? 'Sucesso' : 'Erro'"></h4>
        <p class="text-xs opacity-90 leading-tight mt-1" x-text="message"></p>
    </div>
    <button @click="show = false" class="ml-auto text-white/50 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
</div>

</body>
</html>
