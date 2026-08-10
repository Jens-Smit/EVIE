// Theme Toggle Logic
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.getElementById('theme-icon');
    
    if (html.classList.contains('dark')) {
        html.classList.remove('dark');
        icon.classList.replace('ph-sun', 'ph-moon');
        localStorage.setItem('theme', 'light');
        showToast('Light Mode aktiviert', 'info');
    } else {
        html.classList.add('dark');
        icon.classList.replace('ph-moon', 'ph-sun');
        localStorage.setItem('theme', 'dark');
        showToast('Dark Mode aktiviert', 'info');
    }
}

// Initialize Theme on Load
function initTheme() {
    const savedTheme = localStorage.getItem('theme');
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    if (savedTheme === 'light' || (!savedTheme && !prefersDark)) {
        document.documentElement.classList.remove('dark');
        document.getElementById('theme-icon')?.classList.replace('ph-sun', 'ph-moon');
    } else {
        document.documentElement.classList.add('dark');
        document.getElementById('theme-icon')?.classList.replace('ph-moon', 'ph-sun');
    }
}

// Call on DOM Load
document.addEventListener('DOMContentLoaded', initTheme);