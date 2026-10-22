
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

<script>
// --- PROTEÇÃO CSRF GLOBAL ---
// Injeta automaticamente o token em todos os formulários POST e requisições AJAX,
// evitando ter que editar cada formulário individualmente.
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';
    if (!token) return;

    // 1. Garante o campo hidden em todo submit de formulário POST
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM') return;
        if ((form.method || '').toLowerCase() !== 'post') return;
        if (!form.querySelector('input[name="csrf_token"]')) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'csrf_token';
            input.value = token;
            form.appendChild(input);
        }
    }, true);

    // 2. Intercepta fetch() para anexar o token em requisições que modificam estado
    var originalFetch = window.fetch;
    if (originalFetch) {
        window.fetch = function (resource, init) {
            init = init || {};
            var method = (init.method || (typeof resource === 'object' && resource.method) || 'GET').toUpperCase();
            if (method !== 'GET' && method !== 'HEAD') {
                init.headers = init.headers || {};
                if (init.headers instanceof Headers) {
                    if (!init.headers.has('X-CSRF-Token')) init.headers.set('X-CSRF-Token', token);
                } else {
                    if (!init.headers['X-CSRF-Token']) init.headers['X-CSRF-Token'] = token;
                }
                // Se o corpo for FormData, inclui o token como campo também
                if (init.body instanceof FormData && !init.body.has('csrf_token')) {
                    init.body.append('csrf_token', token);
                }
            }
            return originalFetch.call(this, resource, init);
        };
    }

    // 3. Intercepta XMLHttpRequest
    var origOpen = XMLHttpRequest.prototype.open;
    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method) {
        this._csrfMethod = (method || 'GET').toUpperCase();
        return origOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
        if (this._csrfMethod && this._csrfMethod !== 'GET' && this._csrfMethod !== 'HEAD') {
            try { this.setRequestHeader('X-CSRF-Token', token); } catch (err) {}
        }
        return origSend.apply(this, arguments);
    };
})();
</script>

</body>
</html>
