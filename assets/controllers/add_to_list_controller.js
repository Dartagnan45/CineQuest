// assets/controllers/add_to_list_controller.js
import { Controller } from "@hotwired/stimulus";
import * as Bootstrap from "bootstrap";

/**
 * Contrôleur pour gérer l'ajout de films/séries aux listes via le dropdown "+"
 * Empêche l'affichage de la page JSON et gère les toasts
 */
export default class extends Controller {
    static values = {
        tmdbId: Number,
        tmdbType: String,
    };

    connect() {
        console.log("Add to list controller connected", {
            tmdbId: this.tmdbIdValue,
            tmdbType: this.tmdbTypeValue,
        });

        // Écoute les événements de mise à jour des listes
        document.addEventListener(
            "list-updated",
            this.handleListUpdate.bind(this)
        );
    }

    disconnect() {
        document.removeEventListener(
            "list-updated",
            this.handleListUpdate.bind(this)
        );
    }

    /**
     * Gère l'ajout d'un contenu à une liste
     * IMPORTANT : Empêche la navigation vers la page JSON
     */
    async add(event) {
        // CRITIQUE : Empêche le comportement par défaut du lien
        event.preventDefault();
        event.stopPropagation();

        const link = event.currentTarget;
        const url = link.getAttribute("href");
        const listName = link.textContent.trim();
        const icon = link.querySelector("i");

        if (!url) {
            console.error("URL manquante sur le lien");
            return;
        }

        // Animation de chargement
        const originalIcon = icon ? icon.className : "";
        if (icon) {
            icon.className = "fas fa-spinner fa-spin me-2";
        }

        // Désactive le lien pendant la requête
        link.style.pointerEvents = "none";

        try {
            const response = await fetch(url, {
                method: "GET",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }

            const data = await response.json();

            if (data.message) {
                // Détermine si c'est un succès ou une erreur
                const isSuccess =
                    response.status === 200 || response.status === 201;
                const isConflict = response.status === 409; // Déjà dans la liste

                if (isSuccess) {
                    // Succès
                    this.showToast(`✅ ${data.message}`, "success");

                    // Dispatch un événement pour mettre à jour les badges
                    document.dispatchEvent(
                        new CustomEvent("list-updated", {
                            detail: {
                                tmdbId: this.tmdbIdValue,
                                tmdbType: this.tmdbTypeValue,
                                listName: listName,
                                action: "added",
                            },
                        })
                    );

                    // Ferme le dropdown après ajout réussi
                    this.closeDropdown();
                } else if (isConflict) {
                    // Déjà dans la liste
                    this.showToast(`ℹ️ ${data.message}`, "info");
                } else {
                    // Autre erreur (limite atteinte, etc.)
                    this.showToast(`⚠️ ${data.message}`, "warning");
                }
            } else {
                throw new Error("Réponse JSON invalide");
            }
        } catch (error) {
            console.error("Erreur lors de l'ajout à la liste:", error);
            this.showToast(
                "❌ Erreur lors de l'ajout à la liste. Veuillez réessayer.",
                "danger"
            );
        } finally {
            // Restaure l'icône et réactive le lien
            if (icon) {
                icon.className = originalIcon;
            }
            link.style.pointerEvents = "";
        }
    }

    /**
     * Ferme le dropdown Bootstrap
     */
    closeDropdown() {
        const dropdownButton = this.element.querySelector(
            '[data-bs-toggle="dropdown"]'
        );
        if (dropdownButton) {
            const dropdown = Bootstrap.Dropdown.getInstance(dropdownButton);
            if (dropdown) {
                dropdown.hide();
            }
        }
    }

    /**
     * Gère les événements de mise à jour des listes
     */
    handleListUpdate(event) {
        if (
            event.detail.tmdbId === this.tmdbIdValue &&
            event.detail.tmdbType === this.tmdbTypeValue
        ) {
            // Rafraîchit le badge bookmark
            this.updateBookmarkBadge();
        }
    }

    /**
     * Met à jour le badge bookmark via son contrôleur
     */
    async updateBookmarkBadge() {
        const bookmarkBadge = document.querySelector(
            `[data-bookmark-tmdb-id-value="${this.tmdbIdValue}"][data-bookmark-tmdb-type-value="${this.tmdbTypeValue}"]`
        );

        if (bookmarkBadge && bookmarkBadge.bookmarkController) {
            bookmarkBadge.bookmarkController.refresh();
        }
    }

    /**
     * Affiche un toast de notification Bootstrap
     */
    showToast(message, type = "success") {
        // Crée le container de toasts s'il n'existe pas
        let toastContainer = document.querySelector(".toast-container");
        if (!toastContainer) {
            toastContainer = document.createElement("div");
            toastContainer.className =
                "toast-container position-fixed top-0 end-0 p-3";
            toastContainer.style.zIndex = "9999";
            document.body.appendChild(toastContainer);
        }

        // Détermine la classe Bootstrap selon le type
        let bgClass;
        let textClass = "text-white";
        let iconClass = "btn-close-white";

        switch (type) {
            case "success":
                bgClass = "bg-success";
                break;
            case "info":
                bgClass = "bg-info";
                break;
            case "warning":
                bgClass = "bg-warning";
                textClass = "text-dark";
                iconClass = "";
                break;
            case "danger":
                bgClass = "bg-danger";
                break;
            default:
                bgClass = "bg-primary";
        }

        // Crée le toast
        const toastId = `toast-${Date.now()}`;
        const toast = document.createElement("div");
        toast.className = `toast align-items-center ${textClass} ${bgClass} border-0`;
        toast.id = toastId;
        toast.setAttribute("role", "alert");
        toast.setAttribute("aria-live", "assertive");
        toast.setAttribute("aria-atomic", "true");

        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body fw-bold">${message}</div>
                <button type="button" class="btn-close ${iconClass} me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;

        toastContainer.appendChild(toast);

        // Affiche le toast avec Bootstrap
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
}
