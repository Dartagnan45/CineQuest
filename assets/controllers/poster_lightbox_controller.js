// assets/controllers/poster_lightbox_controller.js

import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        url: String,
    };

    connect() {
        console.log("Poster lightbox controller connected");
    }

    // Ouvrir la lightbox pour agrandir l'image
    open(event) {
        const lightbox = document.getElementById("posterLightbox");
        const lightboxImage = document.getElementById("lightboxImage");

        if (lightbox && lightboxImage) {
            lightboxImage.src = this.urlValue;
            lightbox.style.display = "flex";
            document.body.style.overflow = "hidden";
        }
    }

    // Fermer la lightbox
    close(event) {
        // Vérifier si on clique sur le fond ou sur le bouton close
        if (
            event.target.id === "posterLightbox" ||
            event.target.classList.contains("lightbox-close")
        ) {
            const lightbox = document.getElementById("posterLightbox");
            if (lightbox) {
                lightbox.style.display = "none";
                document.body.style.overflow = "auto";
            }
        }
    }
}
