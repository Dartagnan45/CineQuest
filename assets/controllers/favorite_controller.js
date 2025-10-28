// assets/controllers/favorite_controller.js
import { Controller } from "@hotwired/stimulus";
import * as Bootstrap from "bootstrap";

/**
 * Contrôleur pour gérer l'ajout/retrait des favoris (Mon Panthéon)
 * Gère la mise à jour en temps réel de l'icône étoile
 */
export default class extends Controller {
    static values = {
        tmdbId: Number,
        tmdbType: String,
    };

    connect() {
        console.log("Favorite controller connected", {
            tmdbId: this.tmdbIdValue,
            tmdbType: this.tmdbTypeValue,
        });

        // Vérifier l'état initial
        this.checkInitialState();
    }

    /**
     * Vérifie l'état initial du favori (étoile pleine ou vide)
     */
    async checkInitialState() {
        try {
            const response = await fetch(
                `/mes-listes/check/${this.tmdbTypeValue}/${this.tmdbIdValue}`,
                {
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }
            );

            if (response.ok) {
                const data = await response.json();
                const isFavorite = data.lists.includes("Mon Panthéon");

                const button = this.element;
                if (isFavorite) {
                    button.classList.add("is-favorite");
                } else {
                    button.classList.remove("is-favorite");
                }
            }
        } catch (error) {
            console.error("Erreur vérification état initial:", error);
        }
    }

    /**
     * Toggle l'état favori d'un film/série
     */
    async toggle(event) {
        event.preventDefault();

        const button = this.element;
        const icon = button.querySelector("i");

        // Désactive le bouton pendant la requête
        button.disabled = true;

        // Animation de chargement
        const originalIconClass = icon.className;
        icon.className = "fas fa-spinner fa-spin";

        try {
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
            });

            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }

            const data = await response.json();

            if (data.success) {
                // Restaure l'icône
                icon.className = originalIconClass;

                // Met à jour l'apparence avec animation
                if (data.isFavorite) {
                    button.classList.add("is-favorite");
                    button.setAttribute("title", "Retirer de Mon Panthéon");

                    // Animation étoile qui grossit
                    button.style.transform = "scale(1.3)";
                    setTimeout(() => {
                        button.style.transform = "scale(1)";
                    }, 300);

                    this.showToast("⭐ Ajouté à Mon Panthéon", "success");
                } else {
                    button.classList.remove("is-favorite");
                    button.setAttribute("title", "Ajouter à Mon Panthéon");

                    // Animation étoile qui rétrécit
                    button.style.transform = "scale(0.8)";
                    setTimeout(() => {
                        button.style.transform = "scale(1)";
                    }, 300);

                    this.showToast("❌ Retiré de Mon Panthéon", "info");
                }

                // Émet un événement pour que d'autres contrôleurs se mettent à jour
                document.dispatchEvent(
                    new CustomEvent("list-updated", {
                        detail: {
                            tmdbId: this.tmdbIdValue,
                            tmdbType: this.tmdbTypeValue,
                            listName: "Mon Panthéon",
                            action: data.isFavorite ? "added" : "removed",
                        },
                    })
                );
            } else {
                throw new Error(data.message || "Erreur inconnue");
            }
        } catch (error) {
            console.error("Erreur:", error);
            icon.className = originalIconClass;
            this.showToast(
                "❌ Une erreur est survenue. Veuillez réessayer.",
                "danger"
            );
        } finally {
            button.disabled = false;
        }
    }

    /**
     * Affiche un toast Bootstrap
     */
    showToast(message, type = "success") {
        let toastContainer = document.querySelector(".toast-container");
        if (!toastContainer) {
            toastContainer = document.createElement("div");
            toastContainer.className =
                "toast-container position-fixed top-0 end-0 p-3";
            toastContainer.style.zIndex = "9999";
            document.body.appendChild(toastContainer);
        }

        let bgClass,
            textClass = "text-white",
            iconClass = "btn-close-white";

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

        const toast = document.createElement("div");
        toast.className = `toast align-items-center ${textClass} ${bgClass} border-0`;
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

        const bsToast = new Bootstrap.Toast(toast, {
            autohide: true,
            delay: 3000,
        });
        bsToast.show();

        toast.addEventListener("hidden.bs.toast", () => {
            toast.remove();
        });
    }
}
