// Main application JavaScript
console.log('EVIE Frontend initialized');

// Global event listeners and functions
document.addEventListener('DOMContentLoaded', () => {
    console.log('DOM fully loaded and parsed');
    
    // Initialize all components
    initChatForm();
    initToolApprovalButtons();
});

// Chat form handling
function initChatForm() {
    const chatForm = document.getElementById('chat-form');
    if (chatForm) {
        chatForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const formData = new FormData(chatForm);
            const submitBtn = chatForm.querySelector('button[type="submit"]');
            const originalBtnText = submitBtn.innerHTML;
            
            // Disable button and show loading state
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<span class="spinner"></span> Senden...';
            
            try {
                const response = await fetch(chatForm.action, {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Update chat container with new message
                    const chatContainer = document.getElementById('chat-container');
                    const userMessage = document.createElement('div');
                    userMessage.className = 'message user';
                    userMessage.textContent = formData.get('prompt');
                    
                    const agentMessage = document.createElement('div');
                    agentMessage.className = 'message agent';
                    agentMessage.textContent = data.response;
                    
                    chatContainer.appendChild(userMessage);
                    chatContainer.appendChild(agentMessage);
                    
                    // Clear input and reset button
                    chatForm.reset();
                    chatForm.querySelector('input[name="prompt"], textarea[name="prompt"]').focus();
                    
                    // Scroll to bottom
                    chatContainer.scrollTop = chatContainer.scrollHeight;
                } else {
                    alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.');
            } finally {
                // Re-enable button
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalBtnText;
            }
        });
    }
}

// Tool approval buttons handling
function initToolApprovalButtons() {
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
    button.innerHTML = '<span class="spinner"></span> Verarbeiten...';
    
    try {
        const response = await fetch(`/api/tools/${toolId}/${action}`, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Update UI based on action
            const row = button.closest('tr');
            if (action === 'approve') {
                row.querySelector('.badge-pending').classList.replace('badge-pending', 'badge-approved');
                row.querySelector('.badge-pending').textContent = 'Genehmigt';
            } else {
                row.querySelector('.badge-pending').classList.replace('badge-pending', 'badge-rejected');
                row.querySelector('.badge-pending').textContent = 'Abgelehnt';
            }
            
            // Remove action buttons
            row.querySelectorAll('.approve-tool, .reject-tool').forEach(btn => btn.remove());
            
            // Show success message
            alert('Tool-' + action + ' erfolgreich!');
        } else {
            alert('Fehler: ' + (data.error || 'Unbekannter Fehler'));
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Ein Fehler ist aufgetreten. Bitte versuchen Sie es erneut.');
    } finally {
        button.disabled = false;
        button.innerHTML = originalBtnText;
    }
}