// Toast Notification System
// Non-blocking notifications with auto-dismiss

class ToastManager {
    constructor() {
        this.container = null;
        this.toasts = [];
        this.init();
    }

    init() {
        // Create toast container
        this.container = document.createElement('div');
        this.container.id = 'toast-container';
        this.container.className = 'toast-container';
        document.body.appendChild(this.container);
    }

    show(message, type = 'info', duration = 5000) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        const icon = this.getIcon(type);
        
        toast.innerHTML = `
            <div class="toast-icon">${icon}</div>
            <div class="toast-content">
                <div class="toast-message">${message}</div>
            </div>
            <button class="toast-close" onclick="toastManager.close(this.parentElement)">&times;</button>
        `;

        this.container.appendChild(toast);
        this.toasts.push(toast);

        // Animate in
        setTimeout(() => toast.classList.add('toast-show'), 10);

        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => this.close(toast), duration);
        }

        return toast;
    }

    close(toast) {
        if (!toast) return;
        
        toast.classList.remove('toast-show');
        toast.classList.add('toast-hide');
        
        setTimeout(() => {
            if (toast.parentElement) {
                toast.parentElement.removeChild(toast);
            }
            const index = this.toasts.indexOf(toast);
            if (index > -1) {
                this.toasts.splice(index, 1);
            }
        }, 300);
    }

    getIcon(type) {
        const icons = {
            success: '✅',
            error: '❌',
            warning: '⚠️',
            info: 'ℹ️'
        };
        return icons[type] || icons.info;
    }

    success(message, duration) {
        return this.show(message, 'success', duration);
    }

    error(message, duration) {
        return this.show(message, 'error', duration);
    }

    warning(message, duration) {
        return this.show(message, 'warning', duration);
    }

    info(message, duration) {
        return this.show(message, 'info', duration);
    }

    loading(message) {
        const toast = this.show(message, 'info', 0);
        toast.classList.add('toast-loading');
        return toast;
    }

    updateLoading(toast, message, type = 'success', duration = 3000) {
        toast.classList.remove('toast-loading');
        toast.className = `toast toast-${type} toast-show`;
        
        const messageEl = toast.querySelector('.toast-message');
        const iconEl = toast.querySelector('.toast-icon');
        
        if (messageEl) messageEl.textContent = message;
        if (iconEl) iconEl.textContent = this.getIcon(type);

        if (duration > 0) {
            setTimeout(() => this.close(toast), duration);
        }
    }
}

// Global instance
const toastManager = new ToastManager();

// Convenience functions
window.showToast = (message, type, duration) => toastManager.show(message, type, duration);
window.toast = {
    success: (msg, duration) => toastManager.success(msg, duration),
    error: (msg, duration) => toastManager.error(msg, duration),
    warning: (msg, duration) => toastManager.warning(msg, duration),
    info: (msg, duration) => toastManager.info(msg, duration),
    loading: (msg) => toastManager.loading(msg),
    update: (toast, msg, type, duration) => toastManager.updateLoading(toast, msg, type, duration)
};
