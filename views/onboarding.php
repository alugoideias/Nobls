
<?php include 'layout/header.php'; // Incluindo cabeçalho pois é uma página "full-screen" ?>
<div class="min-h-screen bg-slate-900 flex flex-col items-center justify-center p-4">
    <div class="max-w-xl w-full">
        <!-- Progress Tracker -->
        <div class="flex gap-2 mb-12">
            <div id="progress-1" class="flex-1 h-1.5 rounded-full transition-all duration-500 bg-indigo-500 shadow-[0_0_15px_rgba(99,102,241,0.5)]"></div>
            <div id="progress-2" class="flex-1 h-1.5 rounded-full transition-all duration-500 bg-slate-800"></div>
            <div id="progress-3" class="flex-1 h-1.5 rounded-full transition-all duration-500 bg-slate-800"></div>
            <div id="progress-4" class="flex-1 h-1.5 rounded-full transition-all duration-500 bg-slate-800"></div>
        </div>

        <div class="bg-white rounded-3xl p-8 md:p-12 shadow-2xl relative overflow-hidden min-h-[500px] flex flex-col">
            <div class="absolute top-0 right-0 w-64 h-64 bg-indigo-50 blur-[100px] rounded-full -mr-32 -mt-32"></div>
            
            <div class="flex-1 relative z-10">
                <!-- Step 1 -->
                <div id="step-1" class="animate-fade-in space-y-6">
                    <div class="w-16 h-16 bg-indigo-600 rounded-2xl flex items-center justify-center text-white text-3xl mb-8"><i class="fa-solid fa-crown"></i></div>
                    <h2 class="text-3xl font-extrabold text-slate-900">Salve, <?php echo htmlspecialchars($CURRENT_USER['nome_familia']); ?>!</h2>
                    <p class="text-slate-500 text-lg">Seu reino financeiro foi fundado com sucesso. Vamos configurar os pilares da sua dinastia.</p>
                    <div class="bg-indigo-50 p-4 rounded-xl border border-indigo-100">
                        <p class="text-sm text-indigo-800 font-medium">✨ Você é o Guardião. Sua missão é proteger e multiplicar o patrimônio.</p>
                    </div>
                </div>
                
                <!-- Step 2 -->
                <div id="step-2" class="animate-fade-in space-y-6 hidden">
                    <div class="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center text-white text-3xl mb-8"><i class="fa-solid fa-users"></i></div>
                    <h2 class="text-3xl font-extrabold text-slate-900">A Corte</h2>
                    <p class="text-slate-500">No painel "Minha Família", você poderá convidar cônjuges e herdeiros, definindo permissões específicas para cada membro.</p>
                </div>

                <!-- Step 3 -->
                <div id="step-3" class="animate-fade-in space-y-6 hidden">
                    <div class="w-16 h-16 bg-amber-600 rounded-2xl flex items-center justify-center text-white text-3xl mb-8"><i class="fa-solid fa-gem"></i></div>
                    <h2 class="text-3xl font-extrabold text-slate-900">O Tesouro</h2>
                    <p class="text-slate-500">Conecte contas bancárias, cartões, imóveis e veículos. O Nobl$ centraliza tudo para calcular seu patrimônio líquido em tempo real.</p>
                </div>
                
                <!-- Step 4 -->
                <div id="step-4" class="animate-fade-in space-y-6 text-center hidden">
                    <div class="w-24 h-24 bg-indigo-600 rounded-full flex items-center justify-center text-white text-5xl mb-8 mx-auto animate-bounce"><i class="fa-solid fa-check"></i></div>
                    <h2 class="text-4xl font-black text-slate-900">Tudo Pronto!</h2>
                    <p class="text-slate-500 max-w-sm mx-auto text-lg">Sua jornada para a liberdade financeira começa agora.</p>
                </div>
            </div>

            <button id="next-btn" class="w-full mt-12 py-5 bg-indigo-600 text-white rounded-2xl font-black text-lg tracking-wide shadow-xl shadow-indigo-600/30 hover:bg-indigo-700 transition-all relative z-10 active:scale-95">
                CONTINUAR
            </button>
        </div>
    </div>
    <p class="mt-8 text-slate-500 text-xs font-medium uppercase tracking-[0.2em]">Nobl$ - White Label Finance Solution</p>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    let currentStep = 1;
    const nextBtn = document.getElementById('next-btn');
    const steps = [document.getElementById('step-1'), document.getElementById('step-2'), document.getElementById('step-3'), document.getElementById('step-4')];
    const progresses = [document.getElementById('progress-1'), document.getElementById('progress-2'), document.getElementById('progress-3'), document.getElementById('progress-4')];

    nextBtn.addEventListener('click', () => {
        if (currentStep < 4) {
            steps[currentStep - 1].classList.add('hidden');
            steps[currentStep].classList.remove('hidden');
            progresses[currentStep].classList.remove('bg-slate-800');
            progresses[currentStep].classList.add('bg-indigo-500', 'shadow-[0_0_15px_rgba(99,102,241,0.5)]');
            currentStep++;
            if(currentStep === 4) {
                nextBtn.textContent = 'ENTRAR NO DASHBOARD';
            }
        } else {
            window.location.href = 'index.php?action=complete_onboarding';
        }
    });
});
</script>
<?php include 'layout/footer.php'; ?>