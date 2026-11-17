#!/usr/bin/env node
/**
 * Copia para public/assets os arquivos self-hosted que o Tailwind CLI não empacota:
 *  - resources/js/app.js (JS próprio, sem bundler)
 *  - Alpine.js e @alpinejs/focus (builds "cdn" do npm, sem bundler)
 *  - Tom Select (select com busca): JS completo (com plugins) e o CSS base, que o app.css importa e sobrescreve
 *  - Public Sans variável (woff2 latin e latin-ext) + resources/css/generated/fonts.css
 *
 * As fontes vêm de @fontsource-variable/public-sans: nenhum request a Google Fonts.
 */
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const nm = (...p) => path.join(root, 'node_modules', ...p);
const pub = (...p) => path.join(root, 'public', 'assets', ...p);

function copy(from, to) {
    fs.mkdirSync(path.dirname(to), { recursive: true });
    fs.copyFileSync(from, to);
    console.log(`copy-assets: ${path.relative(root, to)}`);
}

// JavaScript
copy(path.join(root, 'resources', 'js', 'app.js'), pub('js', 'app.js'));
copy(nm('alpinejs', 'dist', 'cdn.min.js'), pub('js', 'vendor', 'alpine.min.js'));
copy(nm('@alpinejs', 'focus', 'dist', 'cdn.min.js'), pub('js', 'vendor', 'alpine-focus.min.js'));
copy(nm('tom-select', 'dist', 'js', 'tom-select.complete.min.js'), pub('js', 'vendor', 'tom-select.min.js'));

// CSS base do Tom Select dentro de @layer components (o Tailwind não aceita @import ... layer()), para as
// regras do app.css sobrescreverem por ordem, sem !important.
const tsCss = fs.readFileSync(nm('tom-select', 'dist', 'css', 'tom-select.css'), 'utf8');
const tsOut = path.join(root, 'resources', 'css', 'generated', 'tom-select.css');
fs.writeFileSync(
    tsOut,
    `/* GERADO por scripts/copy-assets.js a partir de tom-select/dist/css/tom-select.css. Não edite à mão. */
`
        + `@layer components {
${tsCss}
}
`,
);
console.log(`copy-assets: ${path.relative(root, tsOut)} gerado.`);

// Fontes: só os subconjuntos usados por pt-BR/EN/ES.
const fontPkg = nm('@fontsource-variable', 'public-sans');
const subsets = ['latin', 'latin-ext'];
for (const subset of subsets) {
    const file = `public-sans-${subset}-wght-normal.woff2`;
    copy(path.join(fontPkg, 'files', file), pub('fonts', file));
}

// @font-face reaproveitado do fontsource (mesmos unicode-range), com url() apontando para public/assets/fonts.
const source = fs.readFileSync(path.join(fontPkg, 'wght.css'), 'utf8');
const blocks = source.match(/@font-face\s*\{[^}]*\}/g) || [];
const kept = blocks
    .filter((block) => subsets.some((s) => block.includes(`public-sans-${s}-wght-normal.woff2`)))
    .map((block) => block.replace(/url\(\.\/files\//g, 'url(../fonts/').replace(/'woff2-variations'/g, "'woff2'"));

if (kept.length !== subsets.length) {
    console.error(`copy-assets: esperava ${subsets.length} blocos @font-face, achei ${kept.length}.`);
    process.exit(1);
}

const out = path.join(root, 'resources', 'css', 'generated', 'fonts.css');
fs.mkdirSync(path.dirname(out), { recursive: true });
fs.writeFileSync(
    out,
    `/* GERADO por scripts/copy-assets.js a partir de @fontsource-variable/public-sans. Não edite à mão. */\n${kept.join('\n\n')}\n`,
);
console.log(`copy-assets: ${path.relative(root, out)} gerado.`);
