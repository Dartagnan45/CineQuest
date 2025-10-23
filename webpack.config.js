const Encore = require("@symfony/webpack-encore");
const path = require("path");

// Configure l'environnement si nécessaire
if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || "dev");
}

Encore
    // =======================
    // Chemins de sortie
    // =======================
    .setOutputPath("public/build/")
    .setPublicPath("/build")
    // .setManifestKeyPrefix('build/')

    // =======================
    // Entrées principales
    // =======================
    .addEntry("app", "./assets/app.js")

    // Aliases utiles
    .addAliases({
        "@symfony/stimulus-bridge/controllers.json": path.resolve(
            __dirname,
            "assets/controllers.json"
        ),
    })

    // =======================
    // Optimisations
    // =======================
    .splitEntryChunks()
    .enableSingleRuntimeChunk()
    .enableStimulusBridge("./assets/controllers.json")
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())

    // =======================
    // Babel (transpilation)
    // =======================
    .configureBabel(null, {
        plugins: [
            "@babel/plugin-transform-class-properties",
            "@babel/plugin-transform-private-methods",
        ],
    })

    // =======================
    // Sass / SCSS
    // =======================
    .enableSassLoader()

    // =======================
    // Copie automatique des images
    // =======================
    .copyFiles([
        {
            from: "./assets/images",
            to: "images/[path][name].[hash:8].[ext]",
        },
        {
            from: "./assets/icons",
            to: "icons/[name].[ext]",
        },
        {
            from: "./assets/sounds",
            to: "sounds/[path][name].[ext]",
        }
    ])

    // =======================
    // jQuery (si plugins)
    // =======================
    .autoProvidejQuery();

// =======================
// Export final
// =======================
module.exports = Encore.getWebpackConfig();
