// assets/controllers/evie_notifications_controller.js

import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toast'];
    static values = {
        approveUrl: String,
        rejectUrl: String,
    };

    connect() {
        // Initialisiere den Controller
        console.log('EVIE Notifications Controller verbunden');
    }

    /**
     * Genehmigt ein Tool.
     */
    approveTool(event) {
        const toolId = event.target.dataset.toolId;
        const notification = event.target.closest('.evie-notification');

        this.sendApprovalRequest(toolId, 'approve', notification);
    }

    /**
     * Lehnt ein Tool ab.
     */
    rejectTool(event) {
        const toolId = event.target.dataset.toolId;
        const notification = event.target.closest('.evie-notification');

        this.sendApprovalRequest(toolId, 'reject', notification);
    }

    /**
     * Sendet eine Freigabe-/Ablehnungs-Anfrage an den Server.
     */
    sendApprovalRequest(toolId, action, notificationElement) {
        const url = action === 'approve' 
            ? this.approveUrlValue.replace(':id', toolId)
            : this.rejectUrlValue.replace(':id', toolId);

        fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                _token: document.querySelector('meta[name="csrf-token"]')?.content || '',
            }),
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            this.handleApprovalResponse(data, action, notificationElement);
        })
        .catch(error => {
            console.error('Fehler bei der Tool-Freigabe:', error);
            this.showError(notificationElement, 'Fehler bei der Verarbeitung. Bitte versuche es erneut.');
        });
    }

    /**
     * Verarbeitet die Antwort des Servers.
     */
    handleApprovalResponse(data, action, notificationElement) {
        if (data.success === true) {
            // Erfolg: Notification ausblenden
            notificationElement.classList.add('fade-out');
            
            // Nach Animation entfernen
            setTimeout(() => {
                notificationElement.remove();
                
                // Falls keine Notifications mehr übrig sind, Toast ausblenden
                if (this.toastTarget.querySelectorAll('.evie-notification').length === 0) {
                    this.toastTarget.style.display = 'none';
                }
            }, 300);

            // Zeige Erfolgmeldung
            this.showSuccessMessage(data, action);
        } else {
            this.showError(notificationElement, data.message || 'Unbekannte Antwort');
        }
    }

    /**
     * Zeigt eine Erfolgmeldung an.
     */
    showSuccessMessage(data, action) {
        const message = action === 'approve' 
            ? `Tool "${data.tool_name}" wurde freigegeben und ist jetzt verfügbar!`
            : `Tool "${data.tool_name}" wurde abgelehnt.`;

        // Hier könnte eine temporäre Erfolgmeldung angezeigt werden
        console.log('Erfolg:', message);
        
        // Optional: Toast-Benachrichtigung
        this.showToast(message, 'success');
    }

    /**
     * Zeigt eine Fehlermeldung an.
     */
    showError(notificationElement, message) {
        console.error('Fehler:', message);
        
        // Füge Fehlerklasse hinzu
        notificationElement.classList.add('evie-notification-error');
        
        // Zeige Fehlermeldung in der Notification
        const errorElement = document.createElement('div');
        errorElement.className = 'evie-notification-error-message';
        errorElement.textContent = message;
        errorElement.style.cssText = 'color: #dc3545; font-size: 12px; margin-top: 10px;';
        
        notificationElement.appendChild(errorElement);
    }

    /**
     * Zeigt eine Toast-Benachrichtigung an.
     */
    showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `evie-toast evie-toast-${type}`;
        toast.textContent = message;
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#007bff'};
            color: white;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
            z-index: 10001;
            animation: slideUp 0.3s ease-out;
        `;

        document.body.appendChild(toast);

        // Nach 3 Sekunden entfernen
        setTimeout(() => {
            toast.style.animation = 'slideDown 0.3s ease-in forwards';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
}
