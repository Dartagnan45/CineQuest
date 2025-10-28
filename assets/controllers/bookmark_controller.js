// assets/controllers/bookmark_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        tmdbId: Number,
        tmdbType: String,
    };

    connect() {
        // Sauvegarder la référence du controller pour y accéder depuis l'extérieur
        this.element.bookmarkController = this;

        // Initialiser l'état
        this.refresh();

        // Écouter les événements de mise à jour
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
        delete this.element.bookmarkController;
    }

    handleListUpdate(event) {
        if (
            event.detail.tmdbId === this.tmdbIdValue &&
            event.detail.tmdbType === this.tmdbTypeValue
        ) {
            this.refresh();
        }
    }

    async refresh() {
        try {
            const response = await fetch(
                `/mes-listes/check/${this.tmdbTypeValue}/${this.tmdbIdValue}`,
                {
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }
            );

            if (!response.ok) {
                throw new Error("Erreur lors de la récupération des listes");
            }

            const data = await response.json();
            this.updateBadge(data.lists);
        } catch (error) {
            console.error("Erreur lors du rafraîchissement du badge:", error);
        }
    }

    updateBadge(lists) {
        const button = this.element;
        const icon = button.querySelector("i");

        if (lists.length > 0) {
            // Le contenu est dans au moins une liste
            button.classList.add("has-lists");

            // Créer le contenu HTML du tooltip
            const listNames = lists
                .map((name) => `<span class="d-block">📋 ${name}</span>`)
                .join("");
            const tooltipContent = `<div class="text-start"><strong>Dans vos listes :</strong><br>${listNames}</div>`;

            button.setAttribute("data-bs-original-title", tooltipContent);
            button.setAttribute("title", tooltipContent);
        } else {
            // Le contenu n'est dans aucune liste
            button.classList.remove("has-lists");
            button.setAttribute(
                "data-bs-original-title",
                "Pas encore dans vos listes"
            );
            button.setAttribute("title", "Pas encore dans vos listes");
        }

        // Réinitialiser le tooltip Bootstrap pour prendre en compte les changements
        const existingTooltip = bootstrap.Tooltip.getInstance(button);
        if (existingTooltip) {
            existingTooltip.dispose();
        }

        // Recréer le tooltip
        new bootstrap.Tooltip(button, {
            html: true,
            placement: "right",
        });
    }
}
