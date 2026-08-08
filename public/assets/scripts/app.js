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

    chatForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const formData = new FormData(chatForm);
        const prompt = formData.get('prompt');
        if (!prompt) return;

        // Zeige User-Nachricht sofort an
        const chatContainer = document.getElementById('chat-container');
        const userMessage = document.createElement('div');
        userMessage.className = 'message user';
        userMessage.textContent = prompt;
        chatContainer.appendChild(userMessage);
        
        // Scroll zum Ende
        chatContainer.scrollTop = chatContainer.scrollHeight;

        // Deaktiviere Button während des Ladens
        const submitBtn = chatForm.querySelector('button[type="submit"]');
        const originalBtnText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner"></span> Warte...';

        try {
            const response = await fetch(chatForm.action, {
                method: 'POST',
                body: formData,
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
            });

            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }

            const data = await response.json();
            
            // Zeige Agenten-Antwort an
            const agentMessage = document.createElement('div');
            agentMessage.className = 'message agent';
            agentMessage.textContent = data.response || 'Keine Antwort erhalten';
            chatContainer.appendChild(agentMessage);
            
            // Scroll zum Ende
            chatContainer.scrollTop = chatContainer.scrollHeight;

        } catch (error) {
            console.error('Fehler beim Senden der Nachricht:', error);
            const errorMessage = document.createElement('div');
            errorMessage.className = 'message system';
            errorMessage.textContent = `Fehler: ${error.message}`;
            chatContainer.appendChild(errorMessage);
            chatContainer.scrollTop = chatContainer.scrollHeight;
        } finally {
            // Reaktiviere Button
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalBtnText;
            chatForm.reset();
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
