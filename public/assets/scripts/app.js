// Haupt-JavaScript-Datei für EVIE Frontend

// DOM Content Loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

function init() {
    // Initialisiere alle Module
    initTheme();
    initSidebar();
    initModal();
    initChatForm();
    initToolApproval();
    initNavigation();
}

// Hilfsfunktion: HTML escapen
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Hilfsfunktion: Prüfe, ob ein String JSON ist
function isJson(str) {
    try {
        JSON.parse(str);
        return true;
    } catch {
        return false;
    }
}

// Hilfsfunktion: Formatiere die Agenten-Antwort
function formatAgentResponse(response) {
    if (!response) {
        return '<p>Keine Antwort erhalten</p>';
    }

    // 1. JSON-Daten
    if (isJson(response)) {
        try {
            const data = JSON.parse(response);
            if (Array.isArray(data) || (typeof data === 'object' && data !== null)) {
                return `<pre class="language-json"><code>${escapeHtml(JSON.stringify(data, null, 2))}</code></pre>`;
            }
        } catch (e) {
            // Kein gültiges JSON, weiter mit Markdown
        }
    }

    // 2. Markdown (inkl. Code-Blöcke, Tabellen, Listen)
    if (typeof marked !== 'undefined') {
        return marked.parse(response);
    }

    // 3. Fallback: Einfacher Text
    return `<p>${escapeHtml(response)}</p>`;
}

// Mobile Sidebar Logic
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    const isClosed = sidebar.classList.contains('-translate-x-full');
    
    if (isClosed) {
        sidebar.classList.remove('-translate-x-full');
        backdrop.classList.remove('hidden');
        setTimeout(() => backdrop.classList.remove('opacity-0'), 10);
    } else {
        sidebar.classList.add('-translate-x-full');
        backdrop.classList.add('opacity-0');
        setTimeout(() => backdrop.classList.add('hidden'), 300);
    }
}

// Modal Logic
function openModal(title, icon, content) {
    const modal = document.getElementById('global-modal');
    const container = document.getElementById('modal-container');
    
    document.getElementById('modal-title-text').textContent = title;
    const iconEl = document.getElementById('modal-icon');
    iconEl.className = `ph ${icon}`;
    document.getElementById('modal-body').innerHTML = content;
    
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    setTimeout(() => {
        modal.classList.remove('opacity-0');
        container.classList.remove('scale-95');
        container.classList.add('scale-100');
    }, 10);
}

function closeModal() {
    const modal = document.getElementById('global-modal');
    const container = document.getElementById('modal-container');
    
    modal.classList.add('opacity-0');
    container.classList.remove('scale-100');
    container.classList.add('scale-95');
    
    setTimeout(() => {
        modal.classList.add('hidden');
        modal.style.display = 'none';
    }, 300);
}

// Initialize Sidebar
function initSidebar() {
    // Window Resize Handler
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 768) {
            document.getElementById('sidebar-backdrop')?.classList.add('hidden', 'opacity-0');
            document.getElementById('sidebar')?.classList.remove('-translate-x-full');
        }
    });
}

// Initialize Modal
function initModal() {
    // Close Modal on Backdrop Click
    document.getElementById('global-modal')?.addEventListener('click', (e) => {
        if (e.target.id === 'global-modal') {
            closeModal();
        }
    });

    // Close Modal on ESC Key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeModal();
        }
    });
}

// Chat-Formular für AI-Agenten
function initChatForm() {
    const chatForm = document.getElementById('chat-form');
    if (!chatForm) return;

    // Verhindere doppelte Anfragen durch eine Request-ID
    let lastRequestId = null;

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        // Generiere eine eindeutige Request-ID, um Duplikate zu verhindern
        const requestId = Date.now().toString() + Math.random().toString(36).substring(2);
        if (lastRequestId === requestId) {
            console.log('Doppelte Anfrage erkannt, ignoriere.');
            return;
        }
        lastRequestId = requestId;

        const formData = new FormData(chatForm);
        const prompt = formData.get('prompt');
        const userIdentifier = formData.get('user_identifier') || 'default_user';
        
        if (!prompt) {
            console.error('Keine Nachricht eingegeben.');
            return;
        }

        // Zeige User-Nachricht sofort an
        const chatContainer = document.getElementById('chat-container');
        const userMessage = document.createElement('div');
        userMessage.className = 'chat-message user';
        userMessage.innerHTML = `
            <div class="message-bubble user">
                ${escapeHtml(prompt)}
            </div>
        `;
        chatContainer.appendChild(userMessage);
        chatContainer.scrollTop = chatContainer.scrollHeight;

        // Deaktiviere Button während des Ladens
        const submitBtn = chatForm.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner"></span> Warte...';

        try {
            // Sende die Anfrage als JSON an die API
            const response = await fetch(chatForm.action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Request-ID': requestId,
                },
                body: JSON.stringify({
                    message: prompt,
                    user_identifier: userIdentifier,
                }),
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            // Zeige Agenten-Antwort an
            const agentMessage = document.createElement('div');
            agentMessage.className = 'chat-message agent';
            agentMessage.innerHTML = `
                <div class="message-bubble agent">
                    ${formatAgentResponse(data.response || 'Keine Antwort erhalten')}
                </div>
            `;
            chatContainer.appendChild(agentMessage);
            chatContainer.scrollTop = chatContainer.scrollHeight;

            // Highlight.js nach dem Rendern ausführen
            if (typeof hljs !== 'undefined') {
                hljs.highlightAll();
            }

        } catch (error) {
            console.error('Fehler beim Senden der Nachricht:', error);
            const errorMessage = document.createElement('div');
            errorMessage.className = 'chat-message system';
            errorMessage.innerHTML = `
                <div class="message-bubble system">
                    Fehler: ${escapeHtml(error.message)}
                </div>
            `;
            chatContainer.appendChild(errorMessage);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        } finally {
            // Reaktiviere Button
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            chatForm.reset();
            lastRequestId = null; // Zurücksetzen für nächste Anfrage
        }
    });
}

// Tool-Freigabe-Funktionalität
function initToolApproval() {
    const approveButtons = document.querySelectorAll('.approve-tool');
    const rejectButtons = document.querySelectorAll('.reject-tool');

    approveButtons.forEach(button => {
        button.addEventListener('click', async (e) => {
            e.preventDefault();
            const toolId = button.dataset.toolId;
            await handleToolAction(toolId, 'approve', button);
        });
    });

    rejectButtons.forEach(button => {
        button.addEventListener('click', async (e) => {
            e.preventDefault();
            const toolId = button.dataset.toolId;
            await handleToolAction(toolId, 'reject', button);
        });
    });
}

async function handleToolAction(toolId, action, button) {
    const originalBtnText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span class="spinner"></span> Verarbeite...';

    try {
        // Hole den CSRF-Token aus dem Meta-Tag
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

        const response = await fetch(`/api/tools/${toolId}/${action}`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();

        // Zeige Erfolgmeldung
        const alert = document.createElement('div');
        alert.className = `alert alert-success p-4 rounded-lg bg-green-100 text-green-800`;
        alert.textContent = data.message || `Tool erfolgreich ${action === 'approve' ? 'freigegeben' : 'abgelehnt'}`;

        const container = button.closest('.card') || document.querySelector('.main-container');
        if (container) {
            container.prepend(alert);
        }

        // Entferne die Tool-Karte nach erfolgreicher Aktion
        if (action === 'approve' || action === 'reject') {
            setTimeout(() => {
                const card = button.closest('.card');
                if (card) card.remove();
            }, 1000);
        }

    } catch (error) {
        console.error('Fehler bei der Tool-Aktion:', error);
        const alert = document.createElement('div');
        alert.className = `alert alert-error p-4 rounded-lg bg-red-100 text-red-800`;
        alert.textContent = `Fehler: ${error.message}`;
        const mainContainer = document.querySelector('.main-container');
        if (mainContainer) {
            mainContainer.prepend(alert);
        }
    } finally {
        button.disabled = false;
        button.innerHTML = originalBtnText;
    }
}

// Navigation
function initNavigation() {
    const navLinks = document.querySelectorAll('.nav-link');
    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            // Aktiven Link hervorheben
            navLinks.forEach(l => l.classList.remove('active'));
            e.target.classList.add('active');
        });
    });
}