import { Controller } from "@hotwired/stimulus";

/**
 * Contrôleur pour la barre de recherche de la navbar.
 * Gère l'ouverture/fermeture au clic sur la loupe.
 */
export default class extends Controller {
    // Définit les "cibles" (targets) que le contrôleur doit connaître
    static targets = ["form", "input"];

    // Classe CSS à basculer
    static classes = ["active"];

    /**
     * Action : Bascule l'état de la barre de recherche (ouvert/fermé)
     */
    toggle(event) {
        event.stopPropagation(); // Empêche le clic de se propager au document
        this.element.classList.toggle(this.activeClass);

        // Si on vient d'ouvrir la barre, on met le focus sur l'input
        if (this.element.classList.contains(this.activeClass)) {
            this.inputTarget.focus();
        }
    }

    /**
     * Action : Ferme la barre de recherche (utilisé pour les clics à l'extérieur)
     */
    close(event) {
        // Si on clique en dehors du composant (this.element)
        if (
            this.element.classList.contains(this.activeClass) &&
            !this.element.contains(event.target)
        ) {
            this.element.classList.remove(this.activeClass);
        }
    }
}
