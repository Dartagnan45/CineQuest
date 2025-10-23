// assets/app.js

// Import des styles et de Bootstrap
import "./styles/app.scss";
import "./bootstrap.js";
import * as Bootstrap from "bootstrap";

// =============================================================================
// CLASSE POUR LA GESTION DU CARROUSEL
// =============================================================================
class InfiniteCarousel {
    constructor(element) {
        if (!element) return;
        this.container = element;
        this.track = element.querySelector(".endless-carousel-track");
        this.prevBtn = element.querySelector(".carousel-prev");
        this.nextBtn = element.querySelector(".carousel-next");
        if (!this.track || !this.prevBtn || !this.nextBtn) return;

        this.allCards = Array.from(this.track.children);
        if (this.allCards.length === 0) return;

        this.realCardCount = this.allCards.length / 2;
        this.currentIndex = 0;
        this.isMoving = false;
        this.touchStartX = 0;
        this.touchEndX = 0;
        this.minSwipeDistance = 50;
        this.resizeTimeout = null;
        this.resizeDelay = 250;
        this.init();
    }

    calculateDimensions() {
        if (this.allCards.length === 0) return;
        const cardStyle = getComputedStyle(this.allCards[0]);
        this.cardWidth =
            this.allCards[0].offsetWidth +
            parseInt(cardStyle.marginLeft || 0) +
            parseInt(cardStyle.marginRight || 0);
    }

    init() {
        this.calculateDimensions();
        this.setInitialPosition();
        this.attachEvents();
        this.updateActiveCard();
    }

    getCenterPosition(index) {
        const containerWidth = this.container.offsetWidth;
        const cardOffset = this.cardWidth * index;
        const centerOffset = containerWidth / 2 - this.cardWidth / 2;
        return -(cardOffset - centerOffset);
    }

    setInitialPosition() {
        const startCard = document.getElementById("start-card");
        let startIndex = 0;
        if (startCard) {
            const cardIndex = this.allCards
                .slice(0, this.realCardCount)
                .indexOf(startCard);
            if (cardIndex !== -1) startIndex = cardIndex;
        }
        this.currentIndex = startIndex;
        const initialPosition = this.getCenterPosition(this.currentIndex);
        this.track.style.transition = "none";
        this.track.style.transform = `translateX(${initialPosition}px)`;
        void this.track.offsetHeight;
        this.track.style.transition =
            "transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)";
    }

    attachEvents() {
        this.prevBtn.addEventListener("click", this.prev.bind(this));
        this.nextBtn.addEventListener("click", this.next.bind(this));
        this.track.addEventListener(
            "transitionend",
            this.handleTransitionEnd.bind(this)
        );
        this.track.addEventListener(
            "touchstart",
            this.handleTouchStart.bind(this),
            { passive: true }
        );
        this.track.addEventListener(
            "touchend",
            this.handleTouchEnd.bind(this),
            { passive: true }
        );
        window.addEventListener("resize", this.handleResize.bind(this));
    }

    handleTouchStart(e) {
        this.touchStartX = e.changedTouches[0].screenX;
    }

    handleTouchEnd(e) {
        this.touchEndX = e.changedTouches[0].screenX;
        this.handleSwipe();
    }

    handleSwipe() {
        const swipeDistance = this.touchStartX - this.touchEndX;
        if (Math.abs(swipeDistance) < this.minSwipeDistance) return;
        swipeDistance > 0 ? this.next() : this.prev();
    }

    handleResize() {
        clearTimeout(this.resizeTimeout);
        this.resizeTimeout = setTimeout(() => {
            this.calculateDimensions();
            const position = this.getCenterPosition(this.currentIndex);
            this.track.style.transition = "none";
            this.track.style.transform = `translateX(${position}px)`;
            void this.track.offsetHeight;
            this.track.style.transition =
                "transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)";
        }, this.resizeDelay);
    }

    moveTo(index) {
        if (this.isMoving) return;
        this.isMoving = true;
        this.currentIndex = index;
        const position = this.getCenterPosition(this.currentIndex);
        this.track.style.transform = `translateX(${position}px)`;
        this.updateActiveCard();
    }

    next() {
        this.moveTo(this.currentIndex + 1);
    }

    prev() {
        this.moveTo(this.currentIndex - 1);
    }

    handleTransitionEnd() {
        this.isMoving = false;
        if (this.currentIndex >= this.realCardCount) this.currentIndex = 0;
        else if (this.currentIndex < 0)
            this.currentIndex = this.realCardCount - 1;
        this.jumpToCurrentIndex();
    }

    jumpToCurrentIndex() {
        const position = this.getCenterPosition(this.currentIndex);
        this.track.style.transition = "none";
        this.track.style.transform = `translateX(${position}px)`;
        void this.track.offsetHeight;
        this.track.style.transition =
            "transform 0.5s cubic-bezier(0.4, 0, 0.2, 1)";
    }

    updateActiveCard() {
        const normalizedIndex =
            ((this.currentIndex % this.realCardCount) + this.realCardCount) %
            this.realCardCount;
        this.allCards.forEach((card, index) => {
            const cardRealIndex = index % this.realCardCount;
            card.classList.toggle(
                "is-active",
                cardRealIndex === normalizedIndex
            );
        });
    }
}

// =============================================================================
// ÉVÉNEMENTS GLOBAUX
// =============================================================================
document.addEventListener("DOMContentLoaded", () => {
    // Bootstrap tooltips
    const tooltipTriggerList = [].slice.call(
        document.querySelectorAll('[data-bs-toggle="tooltip"]')
    );
    tooltipTriggerList.map((el) => new Bootstrap.Tooltip(el));

    // Carrousel
    const mainCarousel = document.querySelector(".endless-carousel-container");
    if (mainCarousel) new InfiniteCarousel(mainCarousel);

    // Thème clair/sombre avec son
    const toggleBtn = document.getElementById("theme-toggle");
    const sound = document.getElementById("lamp-sound");

    if (localStorage.getItem("theme") === "dark") {
        document.body.classList.add("dark-mode");
    }

    if (toggleBtn) {
        toggleBtn.addEventListener("click", () => {
            if (sound) {
                sound.currentTime = 0;
                sound.play().catch(() => {});
            }
            document.body.classList.toggle("dark-mode");
            localStorage.setItem(
                "theme",
                document.body.classList.contains("dark-mode") ? "dark" : "light"
            );
        });
    }
});

// Cacher l’ampoule au scroll
document.addEventListener("scroll", () => {
    const toggleBtn = document.querySelector(".theme-toggle");
    if (!toggleBtn) return;
    toggleBtn.classList.toggle("hidden", window.scrollY > 100);
});
