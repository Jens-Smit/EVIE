/**
 * EVIE - AI Agent Chat Application
 */

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initChat);
} else {
    initChat();
}

function initChat() {
    const chatForm = document.getElementById('chat-form');
    const chatContainer = document.getElementById('chat-container');
    const promptInput = document.getElementById('prompt');
    
    if (!chatForm || !chatContainer) return;
    
    chatForm.addEventListener('submit', handleFormSubmit);
    scrollToBottom(chatContainer);
    
    if (promptInput) promptInput.focus();
}

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

function createApprovalButton(data) {
    const div = document.createElement('div');
    div.className = 'flex gap-2 justify-center my-2';
    
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

function approveTool(toolId, buttonContainer) {
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
            buttonContainer.remove();
        } else {
            showNotification('Fehler beim Genehmigen des Tools', 'error');
        }
    })
    .catch(error => {
        showNotification('Fehler: ' + error.message, 'error');
        console.error('Approval Error:', error);
    });
}

function rejectTool(toolId, buttonContainer) {
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
            buttonContainer.remove();
        } else {
            showNotification('Fehler beim Ablehnen des Tools', 'error');
        }
    })
    .catch(error => {
        showNotification('Fehler: ' + error.message, 'error');
        console.error('Rejection Error:', error);
    });
}

function addMessageToChat(container, content, role) {
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

function formatMessage(content) {
    try {
        const data = JSON.parse(content);
        if (data && typeof data === 'object') {
            return formatJsonResponse(data);
        }
    } catch (e) {}
    
    if (typeof marked !== 'undefined') {
        return marked.parse(content);
    }
    
    return content;
}

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

function applyFormatting(container) {
    const messages = container.querySelectorAll('.message-bubble.agent');
    messages.forEach(bubble => {
        if (!bubble.querySelector('.markdown-body') && bubble.textContent) {
            bubble.innerHTML = formatMessage(bubble.textContent);
        }
    });
}

function createLoadingMessage() {
    const div = document.createElement('div');
    div.className = 'chat-message system';
    
    const bubble = document.createElement('div');
    bubble.className = 'message-bubble system';
    bubble.innerHTML = '<span class="spinner"></span> Warte auf Antwort...';
    
    div.appendChild(bubble);
    return div;
}

function createErrorMessage(message) {
    const div = document.createElement('div');
    div.className = 'chat-message system';
    
    const bubble = document.createElement('div');
    bubble.className = 'message-bubble system';
    bubble.innerHTML = `<span>❌ Fehler: ${message}</span>`;
    
    div.appendChild(bubble);
    return div;
}

function showNotification(message, type = 'info') {
    const notificationContainer = document.getElementById('notification-container') || createNotificationContainer();
    
    const notification = document.createElement('div');
    notification.className = `notification notification-${type} p-4 mb-2 rounded-lg shadow-lg`;
    notification.innerHTML = `
        <div class="flex items-center gap-2">
            <span>${getNotificationIcon(type)}</span>
            <span>${message}</span>
            <button class="ml-auto text-xl" onclick="this.parentElement.parentElement.remove()">&times;</button>
        </div>
    `;
    
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
    
    notificationContainer.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 5000);
}

function createNotificationContainer() {
    const container = document.createElement('div');
    container.id = 'notification-container';
    container.className = 'fixed top-4 right-4 z-50 w-80';
    document.body.appendChild(container);
    return container;
}

function getNotificationIcon(type) {
    const icons = {
        success: '✅',
        error: '❌',
        info: 'ℹ️',
        warning: '⚠️'
    };
    return icons[type] || 'ℹ️';
}

function scrollToBottom(container) {
    container.scrollTop = container.scrollHeight;
}

function disableButton(button) {
    if (button) {
        button.disabled = true;
        button.classList.add('opacity-50', 'cursor-not-allowed');
    }
}

function enableButton(button) {
    if (button) {
        button.disabled = false;
        button.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}