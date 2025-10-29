// assets/controllers/bookmark_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        tmdbId: Number,
        tmdbType: String,
    };

    connect() {
        // Sauvegarder la rÃ©fÃ©rence du controller pour y accÃ©der depuis l'extÃ©rieur
        this.element.bookmarkController = this;

        // Initialiser l'Ã©tat
        this.refresh();

        // Ãcouter les Ã©vÃ©nements de mise Ã  jour
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
                throw new Error("Erreur lors de la rÃ©cupÃ©ration des listes");
            }

            const data = await response.json();
            this.updateBadge(data.lists);
        } catch (error) {
            console.error("Erreur lors du rafraÃ®chissement du badge:", error);
        }
    }

    updateBadge(lists) {
        const button = this.element;
        const icon = button.querySelector("i");

        if (lists.length > 0) {
            // Le contenu est dans au moins une liste
            button.classList.add("has-lists");

            // CrÃ©er le contenu HTML du tooltip
            const listNames = lists
                .map((name) => `<span class="d-block">ð ${name}</span>`)
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

        // RÃ©initialiser le tooltip Bootstrap pour prendre en compte les changements
        const existingTooltip = bootstrap.Tooltip.getInstance(button);
        if (existingTooltip) {
            existingTooltip.dispose();
        }

        // RecrÃ©er le tooltip
        new bootstrap.Tooltip(button, {
            html: true,
            placement: "right",
        });
    }
}
