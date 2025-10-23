// assets/bootstrap.js
import { startStimulusApp } from "@symfony/stimulus-bridge";

// Démarre Stimulus et enregistre les contrôleurs depuis controllers.json et le dossier controllers/
export const app = startStimulusApp(
    require.context(
        "./controllers", // <- on pointe vers ton dossier local
        true,
        /\.js$/
    )
);
