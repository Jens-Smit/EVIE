// Toast Notification System
function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    
    let icon = 'ph-info';
    let colors = 'bg-slate-800 text-white border-slate-700';
    
    if (type === 'success') {
        icon = 'ph-check-circle text-emerald-400';
        colors = 'bg-slate-900 text-white border-emerald-500/30';
    }
    if (type === 'error') {
        icon = 'ph-warning-circle text-red-400';
        colors = 'bg-slate-900 text-white border-red-500/30';
    }
    
    toast.className = `flex items-center gap-2 px-4 py-2.5 rounded-full 
                     border shadow-xl text-sm font-medium animate-slide-up 
                     pointer-events-auto ${colors}`;
    toast.innerHTML = `<i class="ph ${icon} text-lg"></i> <span>${message}</span>`;
    
    container.appendChild(toast);
    
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translate(-50%, 20px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
