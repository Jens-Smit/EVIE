// Haupt-JavaScript-Datei für EVIE Frontend

// DOM Content Loaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}

function init() {
    // Initialisiere alle Module
    initChatForm();
    initToolApproval();
    initNavigation();
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
        
        if (!prompt) return;

        // Zeige User-Nachricht sofort an
        const chatContainer = document.getElementById('chat-container');
        const userMessage = document.createElement('div');
        userMessage.className = 'mb-4 max-w-[80%] ml-auto text-right';
        userMessage.innerHTML = `
            <div class="inline-block p-4 rounded-lg bg-primary text-white">
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
            const response = await fetch('/api/agent/dialog', {
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
            agentMessage.className = 'mb-4 max-w-[80%] mr-auto';
            agentMessage.innerHTML = `
                <div class="inline-block p-4 rounded-lg bg-gray-200 text-gray-800">
                    ${escapeHtml(data.response || 'Keine Antwort erhalten')}
                </div>
            `;
            chatContainer.appendChild(agentMessage);
            chatContainer.scrollTop = chatContainer.scrollHeight;

        } catch (error) {
            console.error('Fehler beim Senden der Nachricht:', error);
            const errorMessage = document.createElement('div');
            errorMessage.className = 'mb-4 max-w-[80%] mx-auto text-center';
            errorMessage.innerHTML = `
                <div class="inline-block p-4 rounded-lg bg-red-200 text-red-800">
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

// Hilfsfunktion: HTML escapen
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
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
        const response = await fetch(`/api/tools/${toolId}/${action}`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
        });

        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }

        const data = await response.json();
        
        // Zeige Erfolgmeldung
        const alert = document.createElement('div');
        alert.className = `alert alert-success`;
        alert.textContent = data.message || `Tool erfolgreich ${action === 'approve' ? 'freigegeben' : 'abgelehnt'}`;
        
        const container = button.closest('.card') || document.querySelector('.main-container');
        container.prepend(alert);

        // Entferne die Tool-Karte nach erfolgreicher Aktion
        if (action === 'approve' || action === 'reject') {
            setTimeout(() => {
                button.closest('.card').remove();
            }, 1000);
        }

    } catch (error) {
        console.error('Fehler bei der Tool-Aktion:', error);
        const alert = document.createElement('div');
        alert.className = `alert alert-error`;
        alert.textContent = `Fehler: ${error.message}`;
        document.querySelector('.main-container').prepend(alert);
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
