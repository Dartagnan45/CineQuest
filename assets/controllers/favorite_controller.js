// assets/controllers/favorite_controller.js
import { Controller } from "@hotwired/stimulus";
import * as Bootstrap from "bootstrap";

/**
 * Contrôleur pour gérer l'ajout/retrait des favoris (Mon Panthéon)
 * Gère la mise à jour en temps réel de l'icône étoile
 *
 * VERSION CORRIGÉE - 28 octobre 2025
 * Corrections appliquées :
 * - Encodage UTF-8 correct
 * - Gestion des deux noms de liste (Favoris / Mon Panthéon)
 * - Meilleure gestion des erreurs avec logs détaillés
 * - Timeout pour les requêtes réseau
 */
export default class extends Controller {
    static values = {
        tmdbId: Number,
        tmdbType: String,
    };

    // Timeout pour les requêtes (10 secondes)
    static TIMEOUT = 10000;

    connect() {
        console.log("[FavoriteController] ✅ Connected", {
            tmdbId: this.tmdbIdValue,
            tmdbType: this.tmdbTypeValue,
            timestamp: new Date().toISOString(),
        });

        // Vérifier l'état initial
        this.checkInitialState();
    }

    disconnect() {
        console.log("[FavoriteController] ❌ Disconnected");
    }

    /**
     * Vérifie l'état initial du favori (étoile pleine ou vide)
     */
    async checkInitialState() {
        try {
            console.log(
                "[FavoriteController] 🔍 Vérification de l'état initial..."
            );

            const controller = new AbortController();
            const timeoutId = setTimeout(
                () => controller.abort(),
                this.constructor.TIMEOUT
            );

            const response = await fetch(
                `/mes-listes/check/${this.tmdbTypeValue}/${this.tmdbIdValue}`,
                {
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    signal: controller.signal,
                }
            );

            clearTimeout(timeoutId);

            if (!response.ok) {
                console.error(
                    "[FavoriteController] ❌ Erreur de vérification:",
                    {
                        status: response.status,
                        statusText: response.statusText,
                    }
                );
                return;
            }

            const data = await response.json();
            console.log("[FavoriteController] 📊 Données reçues:", data);

            // ✅ CORRECTION MAJEURE : Vérifier les deux noms de liste possibles
            // Anciens comptes peuvent avoir "Favoris", nouveaux ont "Mon Panthéon"
            const isFavorite =
                data.lists.includes("Mon Panthéon") ||
                data.lists.includes("Favoris");

            console.log("[FavoriteController] ⭐ Est un favori:", isFavorite);

            this.updateButtonState(isFavorite);
        } catch (error) {
            if (error.name === "AbortError") {
                console.error(
                    "[FavoriteController] ⏱️ Timeout lors de la vérification"
                );
            } else {
                console.error("[FavoriteController] ❌ Erreur:", error);
            }
        }
    }

    /**
     * Toggle l'état favori d'un film/série
     */
    async toggle(event) {
        event.preventDefault();

        console.log("[FavoriteController] 🔄 Toggle demandé");

        const button = this.element;
        const icon = button.querySelector("i");

        if (!icon) {
            console.error(
                "[FavoriteController] ❌ Icône introuvable dans le bouton"
            );
            return;
        }

        // Désactive le bouton pendant la requête
        button.disabled = true;

        // Animation de chargement
        const originalIconClass = icon.className;
        icon.className = "fas fa-spinner fa-spin";

        const startTime = performance.now();

        try {
            console.log("[FavoriteController] 📤 Envoi de la requête...");

            const controller = new AbortController();
            const timeoutId = setTimeout(
                () => controller.abort(),
                this.constructor.TIMEOUT
            );

            const response = await fetch("/mes-listes/favoris/toggle", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    tmdbId: this.tmdbIdValue,
                    tmdbType: this.tmdbTypeValue,
                }),
                signal: controller.signal,
            });

            clearTimeout(timeoutId);

            const endTime = performance.now();
            const duration = (endTime - startTime).toFixed(0);

            console.log("[FavoriteController] 📥 Réponse reçue", {
                status: response.status,
                statusText: response.statusText,
                duration: `${duration}ms`,
            });

            if (!response.ok) {
                // Tenter de lire le corps de la réponse pour plus d'infos
                let errorDetails = "";
                try {
                    errorDetails = await response.text();
                } catch (e) {
                    errorDetails = "Impossible de lire le corps de l'erreur";
                }

                console.error("[FavoriteController] ❌ Erreur HTTP:", {
                    status: response.status,
                    statusText: response.statusText,
                    details: errorDetails,
                });

                throw new Error(
                    `Erreur HTTP ${response.status}: ${response.statusText}`
                );
            }

            const data = await response.json();
            console.log("[FavoriteController] ✅ Données de réponse:", data);

            if (data.success) {
                // Restaure l'icône
                icon.className = originalIconClass;

                // Met à jour l'apparence avec animation
                if (data.isFavorite) {
                    console.log("[FavoriteController] ⭐ Ajouté aux favoris");

                    button.classList.add("is-favorite");
                    button.setAttribute("title", "Retirer de Mon Panthéon");
                    button.setAttribute(
                        "aria-label",
                        "Retirer de Mon Panthéon"
                    );

                    // Animation étoile qui grossit
                    button.style.transform = "scale(1.3)";
                    setTimeout(() => {
                        button.style.transform = "scale(1)";
                    }, 300);

                    this.showToast("⭐ Ajouté à Mon Panthéon", "success");
                } else {
                    console.log("[FavoriteController] ➖ Retiré des favoris");

                    button.classList.remove("is-favorite");
                    button.setAttribute("title", "Ajouter à Mon Panthéon");
                    button.setAttribute("aria-label", "Ajouter à Mon Panthéon");

                    // Animation étoile qui rétrécit
                    button.style.transform = "scale(0.8)";
                    setTimeout(() => {
                        button.style.transform = "scale(1)";
                    }, 300);

                    this.showToast("❌ Retiré de Mon Panthéon", "info");
                }

                // Émet un événement pour que d'autres contrôleurs se mettent à jour
                this.dispatchListUpdatedEvent(
                    data.isFavorite ? "added" : "removed"
                );
            } else {
                console.error(
                    "[FavoriteController] ❌ Échec de l'opération:",
                    data.message
                );
                throw new Error(data.message || "L'opération a échoué");
            }
        } catch (error) {
            console.error(
                "[FavoriteController] ❌ Erreur lors du toggle:",
                error
            );

            // Restaure l'icône en cas d'erreur
            icon.className = originalIconClass;

            // Affiche un message d'erreur approprié
            if (error.name === "AbortError") {
                this.showToast(
                    "⏱️ La requête a expiré. Vérifiez votre connexion.",
                    "warning"
                );
            } else if (
                error instanceof TypeError &&
                error.message.includes("fetch")
            ) {
                this.showToast(
                    "🌐 Problème de connexion. Vérifiez votre réseau.",
                    "danger"
                );
            } else {
                this.showToast(
                    "❌ Une erreur est survenue. Réessayez dans quelques instants.",
                    "danger"
                );
            }
        } finally {
            // Réactive toujours le bouton
            button.disabled = false;
        }
    }

    /**
     * Met à jour l'état visuel du bouton
     */
    updateButtonState(isFavorite) {
        const button = this.element;

        if (isFavorite) {
            button.classList.add("is-favorite");
            button.setAttribute("title", "Retirer de Mon Panthéon");
            button.setAttribute("aria-label", "Retirer de Mon Panthéon");
        } else {
            button.classList.remove("is-favorite");
            button.setAttribute("title", "Ajouter à Mon Panthéon");
            button.setAttribute("aria-label", "Ajouter à Mon Panthéon");
        }
    }

    /**
     * Émet un événement de mise à jour de liste
     */
    dispatchListUpdatedEvent(action) {
        console.log(
            "[FavoriteController] 📢 Émission de l'événement list-updated:",
            action
        );

        const event = new CustomEvent("list-updated", {
            detail: {
                tmdbId: this.tmdbIdValue,
                tmdbType: this.tmdbTypeValue,
                listName: "Mon Panthéon",
                action: action,
            },
            bubbles: true,
        });

        document.dispatchEvent(event);
    }

    /**
     * Affiche un toast Bootstrap
     */
    showToast(message, type = "success") {
        console.log("[FavoriteController] 🍞 Affichage du toast:", {
            message,
            type,
        });

        let toastContainer = document.querySelector(".toast-container");

        if (!toastContainer) {
            toastContainer = document.createElement("div");
            toastContainer.className =
                "toast-container position-fixed top-0 end-0 p-3";
            toastContainer.style.zIndex = "9999";
            document.body.appendChild(toastContainer);
            console.log("[FavoriteController] 📦 Container de toast créé");
        }

        const { bgClass, textClass, iconClass } = this.getToastClasses(type);

        const toast = document.createElement("div");
        toast.className = `toast align-items-center ${textClass} ${bgClass} border-0`;
        toast.setAttribute("role", "alert");
        toast.setAttribute("aria-live", "assertive");
        toast.setAttribute("aria-atomic", "true");

        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body fw-bold">${message}</div>
                <button type="button" class="btn-close ${iconClass} me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Fermer"></button>
            </div>
        `;

        toastContainer.appendChild(toast);

        const bsToast = new Bootstrap.Toast(toast, {
            autohide: true,
            delay: 3000,
        });

        bsToast.show();

        // Supprime le toast après disparition
        toast.addEventListener("hidden.bs.toast", () => {
            toast.remove();
        });
    }

    /**
     * Retourne les classes CSS pour un type de toast donné
     */
    getToastClasses(type) {
        const classes = {
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
        };

        return classes[type] || classes.success;
    }
}
