/**
 * EVIE - AI Agent Chat Application
 */

// Global notification container reference
let notificationContainer = null;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
} else {
    initAll();
}

/**
 * Initialize all functionality
 */
function initAll() {
    // Initialize notification container
    notificationContainer = document.getElementById('notification-container');
    if (!notificationContainer) {
        notificationContainer = createNotificationContainer();
    }
    
    // Initialize chat if on dialog page
    initChat();
    
    // Initialize tool approval buttons if on pending tools page
    initToolApproval();
}

/**
 * Initialize chat functionality
 */
function initChat() {
    const chatForm = document.getElementById('chat-form');
    const chatContainer = document.getElementById('chat-container');
    const promptInput = document.getElementById('prompt');
    
    if (!chatForm || !chatContainer) return;
    
    chatForm.addEventListener('submit', handleFormSubmit);
    scrollToBottom(chatContainer);
    
    if (promptInput) promptInput.focus();
}

/**
 * Initialize tool approval functionality
 */
function initToolApproval() {
    // Approve buttons
    document.querySelectorAll('.approve-tool').forEach(button => {
        button.addEventListener('click', function() {
            const toolId = this.getAttribute('data-tool-id');
            const buttonContainer = this.closest('.bg-gray-50, .tool-item');
            approveTool(toolId, buttonContainer);
        });
    });
    
    // Reject buttons
    document.querySelectorAll('.reject-tool').forEach(button => {
        button.addEventListener('click', function() {
            const toolId = this.getAttribute('data-tool-id');
            const buttonContainer = this.closest('.bg-gray-50, .tool-item');
            rejectTool(toolId, buttonContainer);
        });
    });
}

/**
 * Behandelt das Senden des Formulars
 */
function handleFormSubmit(event) {
    event.preventDefault();
    
    const form = event.target;
    const chatContainer = document.getElementById('chat-container');
    const promptInput = document.getElementById('prompt');
    const submitButton = form.querySelector('button[type="submit"]');
    
    disableButton(submitButton);
    const message = promptInput.value.trim();
    
    if (!message) {
        enableButton(submitButton);
        return;
    }
    
    addMessageToChat(chatContainer, message, 'user');
    promptInput.value = '';
    scrollToBottom(chatContainer);
    sendMessageToAgent(form, chatContainer, submitButton);
}

/**
 * Sendet die Nachricht an den AI-Agenten
 */
function sendMessageToAgent(form, chatContainer, submitButton) {
    const formData = new FormData(form);
    const endpoint = form.action;
    const method = form.method;
    
    const loadingMessage = createLoadingMessage();
    chatContainer.appendChild(loadingMessage);
    scrollToBottom(chatContainer);
    
    fetch(endpoint, {
        method: method,
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
        body: formData
    })
    .then(response => {
        if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
        return response.json();
    })
    .then(data => {
        chatContainer.removeChild(loadingMessage);
        handleAgentResponse(data, chatContainer);
        enableButton(submitButton);
    })
    .catch(error => {
        chatContainer.removeChild(loadingMessage);
        const errorMessage = createErrorMessage(error.message);
        chatContainer.appendChild(errorMessage);
        scrollToBottom(chatContainer);
        enableButton(submitButton);
        console.error('EVIE Chat Error:', error);
    });
}

/**
 * Behandelt die Antwort des Agenten
 */
function handleAgentResponse(data, chatContainer) {
    let responseText = '';
    
    if (data.requires_tool_approval) {
        responseText = data.response;
        const approvalButton = createApprovalButton(data);
        chatContainer.appendChild(approvalButton);
    } else {
        responseText = data.response || 'Keine Antwort erhalten.';
    }
    
    addMessageToChat(chatContainer, responseText, 'agent');
    scrollToBottom(chatContainer);
    applyFormatting(chatContainer);
}

/**
 * Erstellt einen Genehmigungs-Button für Tools
 */
function createApprovalButton(data) {
    const div = document.createElement('div');
    div.className = 'flex gap-2 justify-center my-2';
    
    // Extrahiere Tool-ID aus der Antwort
    const toolMatch = data.response.match(/\/api\/tools\/(\d+)\/approve/);
    const toolId = toolMatch ? toolMatch[1] : null;
    
    if (toolId) {
        const approveButton = document.createElement('button');
        approveButton.className = 'bg-green-500 text-white px-4 py-2 rounded-lg hover:bg-green-600 transition';
        approveButton.innerHTML = '<span>👍 Tool freigeben</span>';
        approveButton.addEventListener('click', () => approveTool(toolId, div));
        
        const rejectButton = document.createElement('button');
        rejectButton.className = 'bg-red-500 text-white px-4 py-2 rounded-lg hover:bg-red-600 transition';
        rejectButton.innerHTML = '<span>👎 Tool ablehnen</span>';
        rejectButton.addEventListener('click', () => rejectTool(toolId, div));
        
        div.appendChild(approveButton);
        div.appendChild(rejectButton);
    }
    
    return div;
}

/**
 * Genehmigt ein Tool
 */
function approveTool(toolId, buttonContainer) {
    if (!buttonContainer) {
        console.error('Button container not found');
        return;
    }
    
    disableButton(buttonContainer.querySelector('button'));
    
    fetch(`/api/tools/${toolId}/approve`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success || data.status === 'success') {
            showNotification('Tool erfolgreich genehmigt!', 'success');
            if (buttonContainer && buttonContainer.remove) {
                buttonContainer.remove();
            }
        } else {
            showNotification('Fehler beim Genehmigen des Tools', 'error');
        }
    })
    .catch(error => {
        showNotification('Fehler: ' + error.message, 'error');
        console.error('Approval Error:', error);
    });
}

/**
 * Lehnt ein Tool ab
 */
function rejectTool(toolId, buttonContainer) {
    if (!buttonContainer) {
        console.error('Button container not found');
        return;
    }
    
    disableButton(buttonContainer.querySelector('button'));
    
    fetch(`/api/tools/${toolId}/reject`, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success || data.status === 'success') {
            showNotification('Tool erfolgreich abgelehnt', 'info');
            if (buttonContainer && buttonContainer.remove) {
                buttonContainer.remove();
            }
        } else {
            showNotification('Fehler beim Ablehnen des Tools', 'error');
        }
    })
    .catch(error => {
        showNotification('Fehler: ' + error.message, 'error');
        console.error('Rejection Error:', error);
    });
}

/**
 * Fügt eine Nachricht zum Chat hinzu
 */
function addMessageToChat(container, content, role) {
    if (!container) {
        console.error('Chat container not found');
        return null;
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `chat-message ${role}`;
    
    const bubbleDiv = document.createElement('div');
    bubbleDiv.className = `message-bubble ${role}`;
    
    if (role === 'agent') {
        bubbleDiv.innerHTML = formatMessage(content);
    } else {
        bubbleDiv.textContent = content;
    }
    
    messageDiv.appendChild(bubbleDiv);
    container.appendChild(messageDiv);
    
    return messageDiv;
}

/**
 * Formatiert eine Nachricht
 */
function formatMessage(content) {
    if (!content) return '';
    
    try {
        const data = JSON.parse(content);
        if (data && typeof data === 'object') {
            return formatJsonResponse(data);
        }
    } catch (e) {
        // Kein JSON, normal formatieren
    }
    
    if (typeof marked !== 'undefined') {
        return marked.parse(content);
    }
    
    return content;
}

/**
 * Formatiert eine JSON-Antwort
 */
function formatJsonResponse(data) {
    const container = document.createElement('div');
    container.className = 'markdown-body';
    
    if (data.type === 'website_research_result') {
        container.innerHTML = `
            <h3>🌐 Webseiten-Recherche: ${data.url || 'Unbekannte URL'}</h3>
            <div class="mt-2">
                <h4 class="font-bold">📋 Zusammenfassung:</h4>
                <p>${data.zusammenfassung || data.summary || 'Keine Zusammenfassung verfügbar'}</p>
            </div>
            ${data.impressum ? `
            <div class="mt-4">
                <h4 class="font-bold">📄 Impressum:</h4>
                <pre class="bg-gray-100 p-2 rounded">${JSON.stringify(data.impressum, null, 2)}</pre>
            </div>
            ` : ''}
            ${data.kontakte && data.kontakte.length > 0 ? `
            <div class="mt-4">
                <h4 class="font-bold">📞 Kontakte:</h4>
                <ul class="list-disc list-inside">
                    ${data.kontakte.map(contact => `
                        <li>${contact.name || 'Unbekannt'}: ${contact.email || ''} ${contact.telefon ? `(${contact.telefon})` : ''}</li>
                    `).join('')}
                </ul>
            </div>
            ` : ''}
            ${data.geschäftszweck ? `
            <div class="mt-4">
                <h4 class="font-bold">🎯 Geschäftszweck:</h4>
                <p>${data.geschäftszweck}</p>
            </div>
            ` : ''}
            ${data.standort ? `
            <div class="mt-4">
                <h4 class="font-bold">📍 Standort:</h4>
                <p>${data.standort}</p>
            </div>
            ` : ''}
            ${data.branche ? `
            <div class="mt-4">
                <h4 class="font-bold">🏢 Branche:</h4>
                <p>${data.branche}</p>
            </div>
            ` : ''}
        `;
    } else if (data.type === 'error') {
        container.innerHTML = `
            <div class="bg-red-50 border-l-4 border-red-500 p-4">
                <h4 class="font-bold text-red-700">❌ Fehler:</h4>
                <p class="text-red-600">${data.error_message || data.message || 'Unbekannter Fehler'}</p>
            </div>
        `;
    } else if (data.type === 'dialog') {
        container.textContent = data.content || data.message || content;
    } else {
        container.innerHTML = `<pre class="bg-gray-100 p-2 rounded">${JSON.stringify(data, null, 2)}</pre>`;
    }
    
    return container.innerHTML;
}

/**
 * Wendet Formatierung auf alle Nachrichten an
 */
function applyFormatting(container) {
    if (!container) return;
    
    const messages = container.querySelectorAll('.message-bubble.agent');
    messages.forEach(bubble => {
        if (!bubble.querySelector('.markdown-body') && bubble.textContent) {
            bubble.innerHTML = formatMessage(bubble.textContent);
        }
    });
}

/**
 * Erstellt eine Lade-Nachricht
 */
function createLoadingMessage() {
    const div = document.createElement('div');
    div.className = 'chat-message system';
    
    const bubble = document.createElement('div');
    bubble.className = 'message-bubble system';
    bubble.innerHTML = '<span class="spinner"></span> Warte auf Antwort...';
    
    div.appendChild(bubble);
    return div;
}

/**
 * Erstellt eine Fehler-Nachricht
 */
function createErrorMessage(message) {
    const div = document.createElement('div');
    div.className = 'chat-message system';
    
    const bubble = document.createElement('div');
    bubble.className = 'message-bubble system';
    bubble.innerHTML = `<span>❌ Fehler: ${message}</span>`;
    
    div.appendChild(bubble);
    return div;
}

/**
 * Zeigt eine Benachrichtigung an
 */
function showNotification(message, type = 'info') {
    // Ensure notification container exists
    if (!notificationContainer) {
        notificationContainer = document.getElementById('notification-container');
    }
    if (!notificationContainer) {
        notificationContainer = createNotificationContainer();
    }
    
    const notification = document.createElement('div');
    notification.className = `notification notification-${type} p-4 mb-2 rounded-lg shadow-lg`;
    notification.innerHTML = `
        <div class="flex items-center gap-2">
            <span>${getNotificationIcon(type)}</span>
            <span>${message}</span>
            <button class="ml-auto text-xl" onclick="this.parentElement.parentElement.remove()">&times;</button>
        </div>
    `;
    
    // Set styles based on type
    if (type === 'success') {
        notification.style.backgroundColor = '#dcfce7';
        notification.style.borderLeft = '4px solid #16a34a';
        notification.style.color = '#15803d';
    } else if (type === 'error') {
        notification.style.backgroundColor = '#fef2f2';
        notification.style.borderLeft = '4px solid #dc2626';
        notification.style.color = '#b91c1c';
    } else if (type === 'info') {
        notification.style.backgroundColor = '#dbeafe';
        notification.style.borderLeft = '4px solid #2563eb';
        notification.style.color = '#1d4ed8';
    }
    
    // Append notification to container
    if (notificationContainer) {
        notificationContainer.appendChild(notification);
        
        // Remove after 5 seconds
        setTimeout(() => {
            if (notification && notification.parentNode) {
                notification.remove();
            }
        }, 5000);
    } else {
        console.error('Notification container not found');
    }
}

/**
 * Erstellt den Benachrichtigungs-Container
 */
function createNotificationContainer() {
    const container = document.createElement('div');
    container.id = 'notification-container';
    container.className = 'fixed top-4 right-4 z-50 w-80';
    document.body.appendChild(container);
    return container;
}

/**
 * Gibt das passende Icon für Benachrichtigungen zurück
 */
function getNotificationIcon(type) {
    const icons = {
        success: '✅',
        error: '❌',
        info: 'ℹ️',
        warning: '⚠️'
    };
    return icons[type] || 'ℹ️';
}

/**
 * Scrollt zum unteren Ende des Containers
 */
function scrollToBottom(container) {
    if (container) {
        container.scrollTop = container.scrollHeight;
    }
}

/**
 * Deaktiviert einen Button
 */
function disableButton(button) {
    if (button) {
        button.disabled = true;
        button.classList.add('opacity-50', 'cursor-not-allowed');
    }
}

/**
 * Aktiviert einen Button
 */
function enableButton(button) {
    if (button) {
        button.disabled = false;
        button.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}

// Make functions globally available for inline event handlers
window.approveTool = approveTool;
window.rejectTool = rejectTool;
window.showNotification = showNotification;
