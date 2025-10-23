// scripts/patch-webpack-cli.js
const fs = require("fs");
const path = require("path");

const cliPath = path.resolve(
    __dirname,
    "../node_modules/webpack-cli/lib/webpack-cli.js"
);

if (fs.existsSync(cliPath)) {
    let code = fs.readFileSync(cliPath, "utf8");

    // Correction du bug de résolution de 'interpret' et 'rechoir'
    if (!code.includes('require.resolve("interpret")')) {
        code = code.replace(
            "require('interpret');",
            "require(require.resolve('interpret', { paths: [process.cwd()] }));"
        );
        code = code.replace(
            "require('rechoir');",
            "require(require.resolve('rechoir', { paths: [process.cwd()] }));"
        );

        fs.writeFileSync(cliPath, code, "utf8");
        console.log(
            "✅ Patch applied to webpack-cli to fix interpret/rechoir resolution."
        );
    } else {
        console.log("ℹ️ Patch already applied.");
    }
} else {
    console.error("❌ webpack-cli.js not found, patch failed.");
}
