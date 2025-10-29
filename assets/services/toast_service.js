// =============================================================================
// SERVICE TOAST - CinéQuest
// =============================================================================
// Service centralisé pour afficher des notifications toast Bootstrap
// Évite la duplication de code dans les contrôleurs Stimulus
// =============================================================================

import * as Bootstrap from "bootstrap";

/**
 * Service pour gérer l'affichage des toasts Bootstrap
 */
export class ToastService {
    /**
     * Affiche un toast de notification
     *
     * @param {string} message - Le message à afficher
     * @param {string} type - Type de toast : 'success', 'info', 'warning', 'danger'
     * @param {number} delay - Durée d'affichage en ms (défaut: 3000)
     * @param {Object} options - Options supplémentaires
     * @param {boolean} options.autohide - Masquer automatiquement (défaut: true)
     * @param {string} options.position - Position : 'top-end', 'top-start', 'bottom-end', 'bottom-start' (défaut: 'top-end')
     *
     * @example
     * // Toast de succès simple
     * ToastService.show("✅ Enregistré avec succès", "success");
     *
     * @example
     * // Toast d'erreur avec durée personnalisée
     * ToastService.show("❌ Une erreur est survenue", "danger", 5000);
     *
     * @example
     * // Toast d'info en bas à gauche
     * ToastService.show("ℹ️ Information", "info", 3000, { position: 'bottom-start' });
     */
    static show(message, type = "success", delay = 3000, options = {}) {
        // Options par défaut
        const defaultOptions = {
            autohide: true,
            position: "top-end",
        };

        const finalOptions = { ...defaultOptions, ...options };

        // Récupère ou crée le container de toasts
        let toastContainer = this.getOrCreateContainer(finalOptions.position);

        // Récupère les styles selon le type
        const { bgClass, textClass, iconClass } = this.getToastStyles(type);

        // Crée l'élément toast
        const toast = this.createToastElement(
            message,
            bgClass,
            textClass,
            iconClass
        );

        // Ajoute le toast au container
        toastContainer.appendChild(toast);

        // Initialise et affiche le toast Bootstrap
        const bsToast = new Bootstrap.Toast(toast, {
            autohide: finalOptions.autohide,
            delay: delay,
        });

        bsToast.show();

        // Supprime le toast du DOM après disparition
        toast.addEventListener("hidden.bs.toast", () => {
            toast.remove();
            // Si le container est vide, le supprimer aussi
            if (toastContainer.children.length === 0) {
                toastContainer.remove();
            }
        });

        return bsToast;
    }

    /**
     * Récupère ou crée le container de toasts
     *
     * @param {string} position - Position du container
     * @returns {HTMLElement} Le container de toasts
     */
    static getOrCreateContainer(position) {
        const positionClass = this.getPositionClass(position);
        const selector = `.toast-container.${positionClass.replace(/ /g, ".")}`;

        let toastContainer = document.querySelector(selector);

        if (!toastContainer) {
            toastContainer = document.createElement("div");
            toastContainer.className = `toast-container ${positionClass} p-3`;
            toastContainer.style.zIndex = "9999";
            document.body.appendChild(toastContainer);
        }

        return toastContainer;
    }

    /**
     * Détermine la classe CSS de position
     *
     * @param {string} position - Position demandée
     * @returns {string} Classe CSS Bootstrap
     */
    static getPositionClass(position) {
        const positions = {
            "top-end": "position-fixed top-0 end-0",
            "top-start": "position-fixed top-0 start-0",
            "bottom-end": "position-fixed bottom-0 end-0",
            "bottom-start": "position-fixed bottom-0 start-0",
        };

        return positions[position] || positions["top-end"];
    }

    /**
     * Crée l'élément DOM du toast
     *
     * @param {string} message - Message à afficher
     * @param {string} bgClass - Classe de fond
     * @param {string} textClass - Classe de texte
     * @param {string} iconClass - Classe d'icône de fermeture
     * @returns {HTMLElement} L'élément toast
     */
    static createToastElement(message, bgClass, textClass, iconClass) {
        const toast = document.createElement("div");
        toast.className = `toast align-items-center ${textClass} ${bgClass} border-0`;
        toast.setAttribute("role", "alert");
        toast.setAttribute("aria-live", "assertive");
        toast.setAttribute("aria-atomic", "true");

        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body fw-bold">${message}</div>
                <button type="button" class="btn-close ${iconClass} me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        return toast;
    }

    /**
     * Détermine les classes CSS selon le type de toast
     *
     * @param {string} type - Type de toast
     * @returns {Object} Objet contenant les classes CSS
     */
    static getToastStyles(type) {
        const styles = {
            success: {
                bgClass: "bg-success",
                textClass: "text-white",
                iconClass: "btn-close-white",
            },
            info: {
                bgClass: "bg-info",
                textClass: "text-white",
                iconClass: "btn-close-white",
            },
            warning: {
                bgClass: "bg-warning",
                textClass: "text-dark",
                iconClass: "",
            },
            danger: {
                bgClass: "bg-danger",
                textClass: "text-white",
                iconClass: "btn-close-white",
            },
            primary: {
                bgClass: "bg-primary",
                textClass: "text-white",
                iconClass: "btn-close-white",
            },
        };

        return styles[type] || styles.success;
    }

    /**
     * Affiche un toast de succès
     *
     * @param {string} message - Message de succès
     * @param {number} delay - Durée d'affichage (défaut: 3000ms)
     */
    static success(message, delay = 3000) {
        return this.show(message, "success", delay);
    }

    /**
     * Affiche un toast d'information
     *
     * @param {string} message - Message d'information
     * @param {number} delay - Durée d'affichage (défaut: 3000ms)
     */
    static info(message, delay = 3000) {
        return this.show(message, "info", delay);
    }

    /**
     * Affiche un toast d'avertissement
     *
     * @param {string} message - Message d'avertissement
     * @param {number} delay - Durée d'affichage (défaut: 4000ms)
     */
    static warning(message, delay = 4000) {
        return this.show(message, "warning", delay);
    }

    /**
     * Affiche un toast d'erreur
     *
     * @param {string} message - Message d'erreur
     * @param {number} delay - Durée d'affichage (défaut: 5000ms)
     */
    static error(message, delay = 5000) {
        return this.show(message, "danger", delay);
    }

    /**
     * Masque tous les toasts visibles
     */
    static hideAll() {
        const toasts = document.querySelectorAll(".toast.show");
        toasts.forEach((toastElement) => {
            const bsToast = Bootstrap.Toast.getInstance(toastElement);
            if (bsToast) {
                bsToast.hide();
            }
        });
    }
}

// Export par défaut pour compatibilité
export default ToastService;
