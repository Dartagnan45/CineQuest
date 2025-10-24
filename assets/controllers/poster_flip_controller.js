// assets/controllers/poster_flip_controller.js

import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    connect() {
        console.log("Poster flip controller connected");
    }

    // Flip entre recto et verso
    flip(event) {
        event.preventDefault();
        event.stopPropagation();

        const container = this.element.querySelector(".poster-flip-inner");
        if (container) {
            container.classList.toggle("flipped");
        }
    }

    // Ouvrir la lightbox pour agrandir l'image
    openLightbox(event) {
        const lightbox = document.getElementById("posterLightbox");
        const lightboxImage = document.getElementById("lightboxImage");

        if (lightbox && lightboxImage && event.target.tagName === "IMG") {
            lightboxImage.src = event.target.src.replace(
                "/w500/",
                "/original/"
            );
            lightbox.style.display = "flex";
            document.body.style.overflow = "hidden";
        }
    }

    // Fermer la lightbox
    closeLightbox(event) {
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
