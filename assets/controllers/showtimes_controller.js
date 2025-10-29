// assets/controllers/showtimes_controller.js
import { Controller } from "@hotwired/stimulus";
import * as Bootstrap from "bootstrap";

/**
 * Contrôleur pour gérer la recherche de séances de cinéma à proximité
 */
export default class extends Controller {
    static values = {
        movieId: Number,
        movieTitle: String,
    };

    static targets = ["results"];

    connect() {
        console.log("Showtimes controller connected", {
            movieId: this.movieIdValue,
            movieTitle: this.movieTitleValue,
        });
    }

    /**
     * Trouve les séances de cinéma à proximité en utilisant la géolocalisation
     */
    async findNearby(event) {
        event.preventDefault();

        // Vérifie que la géolocalisation est disponible
        if (!navigator.geolocation) {
            this.showError("La géolocalisation n'est pas supportée par votre navigateur.");
            return;
        }

        // Affiche un loader
        this.showLoading();

        // Demande la position de l'utilisateur
        navigator.geolocation.getCurrentPosition(
            (position) => this.searchShowtimes(position),
            (error) => this.handleGeolocationError(error),
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0,
            }
        );
    }

    /**
     * Recherche les séances à partir des coordonnées GPS
     */
    async searchShowtimes(position) {
        const { latitude, longitude } = position.coords;

        try {
            const response = await fetch(
                `/api/showtimes/nearby?lat=${latitude}&lng=${longitude}&movieId=${this.movieIdValue}`,
                {
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }
            );

            if (!response.ok) {
                throw new Error(`Erreur HTTP: ${response.status}`);
            }

            const data = await response.json();

            if (data.success && data.cinemas && data.cinemas.length > 0) {
                this.displayResults(data.cinemas);
            } else {
                this.showNoResults();
            }
        } catch (error) {
            console.error("Erreur lors de la recherche de séances:", error);
            this.showError(
                "Une erreur est survenue lors de la recherche. Veuillez réessayer."
            );
        }
    }

    /**
     * Affiche les résultats dans la page
     */
    displayResults(cinemas) {
        const html = `
            <div class="showtimes-results">
                <h5 class="text-white mb-3">
                    <i class="fas fa-map-marker-alt text-danger me-2"></i>
                    ${cinemas.length} cinéma(s) trouvé(s) près de vous
                </h5>
                ${cinemas
                    .map(
                        (cinema) => `
                    <div class="cinema-card bg-dark rounded-3 p-3 mb-3 shadow">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <div>
                                <h6 class="text-white mb-1">
                                    <i class="fas fa-film text-primary me-2"></i>
                                    ${cinema.name}
                                </h6>
                                <p class="text-muted small mb-0">
                                    <i class="fas fa-map-marker-alt me-1"></i>
                                    ${cinema.address || "Adresse non disponible"}
                                </p>
                            </div>
                            <span class="badge bg-info">
                                ${cinema.distance ? cinema.distance.toFixed(1) + " km" : ""}
                            </span>
                        </div>

                        ${
                            cinema.showtimes && cinema.showtimes.length > 0
                                ? `
                            <div class="showtimes-list mt-2">
                                <p class="text-white-50 small mb-2">
                                    <i class="fas fa-clock me-1"></i>Séances disponibles :
                                </p>
                                <div class="d-flex flex-wrap gap-2">
                                    ${cinema.showtimes
                                        .map(
                                            (showtime) => `
                                        <span class="badge bg-warning text-dark">
                                            ${showtime.time}
                                        </span>
                                    `
                                        )
                                        .join("")}
                                </div>
                            </div>
                        `
                                : `
                            <p class="text-muted small mb-0 mt-2">
                                <i class="fas fa-info-circle me-1"></i>
                                Horaires non disponibles - Contactez le cinéma
                            </p>
                        `
                        }

                        ${
                            cinema.phone
                                ? `
                            <p class="text-white-50 small mb-0 mt-2">
                                <i class="fas fa-phone me-1"></i>
                                ${cinema.phone}
                            </p>
                        `
                                : ""
                        }

                        ${
                            cinema.website
                                ? `
                            <a href="${cinema.website}" target="_blank" rel="noopener noreferrer"
                               class="btn btn-sm btn-outline-primary mt-2">
                                <i class="fas fa-external-link-alt me-1"></i>
                                Voir les horaires sur le site
                            </a>
                        `
                                : ""
                        }
                    </div>
                `
                    )
                    .join("")}
            </div>
        `;

        this.resultsTarget.innerHTML = html;
    }

    /**
     * Affiche un message quand aucun cinéma n'est trouvé
     */
    showNoResults() {
        this.resultsTarget.innerHTML = `
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Aucun cinéma trouvé près de votre position avec ce film à l'affiche.
            </div>
        `;
    }

    /**
     * Affiche un loader pendant la recherche
     */
    showLoading() {
        this.resultsTarget.innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Recherche en cours...</span>
                </div>
                <p class="text-white-50 mt-3">
                    <i class="fas fa-search me-2"></i>
                    Recherche des cinémas près de vous...
                </p>
            </div>
        `;
    }

    /**
     * Affiche un message d'erreur
     */
    showError(message) {
        this.resultsTarget.innerHTML = `
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                ${message}
            </div>
        `;
    }

    /**
     * Gère les erreurs de géolocalisation
     */
    handleGeolocationError(error) {
        let message = "Impossible d'obtenir votre position.";

        switch (error.code) {
            case error.PERMISSION_DENIED:
                message =
                    "Vous avez refusé l'autorisation de géolocalisation. Veuillez l'activer dans les paramètres de votre navigateur.";
                break;
            case error.POSITION_UNAVAILABLE:
                message = "Votre position n'est pas disponible actuellement.";
                break;
            case error.TIMEOUT:
                message = "La demande de géolocalisation a expiré. Veuillez réessayer.";
                break;
        }

        this.showError(message);
    }
}
