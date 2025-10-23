// webpack-fix.js
// Patch de compatibilité pour Node 22 + webpack-cli 5.x
const Module = require("module");
const originalResolveFilename = Module._resolveFilename;

Module._resolveFilename = function (request, parent, isMain, options) {
    if (request === "interpret" || request === "rechoir") {
        try {
            // tente de résoudre à partir du projet racine
            return require.resolve(request, { paths: [process.cwd()] });
        } catch (e) {
            console.warn(
                `⚠️  Impossible de résoudre ${request}, tentative via chemin par défaut.`
            );
        }
    }
    return originalResolveFilename.call(this, request, parent, isMain, options);
};
