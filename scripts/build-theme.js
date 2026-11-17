#!/usr/bin/env node
/**
 * Gera resources/css/generated/theme.css (bloco @theme do Tailwind v4)
 * a partir de design/design-system/tokens.json.
 *
 * O tokens.json é a fonte única de verdade: não copie valores dele à mão.
 * Rode `npm run build:theme` (ou `npm run build`) após qualquer alteração.
 */
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const tokensFile = path.join(root, 'design', 'design-system', 'tokens.json');
const outFile = path.join(root, 'resources', 'css', 'generated', 'theme.css');

// O fontsource registra a variável como "Public Sans Variable".
const FONT_FAMILY_ALIASES = { '"Public Sans"': '"Public Sans Variable"' };

function fail(message) {
    console.error(`build-theme: ${message}`);
    process.exit(1);
}

const tokens = JSON.parse(fs.readFileSync(tokensFile, 'utf8'));
const lines = [];

// Cores: "{brand}" referencia outro token de cor.
const colorNames = new Set(tokens.color.tokens.map((t) => t.name));
lines.push('  /* Cores */');
for (const token of tokens.color.tokens) {
    let value = token.value.default;
    const ref = /^\{(.+)\}$/.exec(value);
    if (ref) {
        if (!colorNames.has(ref[1])) {
            fail(`token "${token.name}" referencia "${ref[1]}", que não existe.`);
        }
        value = `var(--color-${ref[1]})`;
    }
    lines.push(`  --color-${token.name}: ${value};`);
}

// Tipografia: cada estilo define tamanho, altura de linha e peso juntos.
const families = tokens.type.families;
lines.push('', '  /* Tipografia */');
for (const [name, stack] of Object.entries(families)) {
    let resolved = stack;
    for (const [from, to] of Object.entries(FONT_FAMILY_ALIASES)) {
        if (resolved.startsWith(from)) {
            resolved = `${to}, ${resolved}`;
        }
    }
    lines.push(`  --font-${name}: ${resolved};`);
}
for (const group of tokens.type.groups) {
    for (const style of group.styles) {
        lines.push(`  --text-${style.name}: ${style.fontSize};`);
        lines.push(`  --text-${style.name}--line-height: ${style.lineHeight};`);
        lines.push(`  --text-${style.name}--font-weight: ${style.fontWeight};`);
    }
}

// Espaçamento: mantém o nome do token ("space-4" => p-space-4).
lines.push('', '  /* Espaçamento */');
for (const token of tokens.spacing.tokens) {
    lines.push(`  --spacing-${token.name}: ${token.value};`);
}

// Raios: "radius-sm" => --radius-sm (rounded-sm).
lines.push('', '  /* Raios */');
for (const token of tokens.radius.tokens) {
    lines.push(`  --radius-${token.name.replace(/^radius-/, '')}: ${token.value};`);
}

// Sombras: "shadow-xs" => --shadow-xs (shadow-xs). Sempre junto de uma borda, nunca no lugar dela.
lines.push('', '  /* Sombras */');
for (const token of tokens.shadow.tokens) {
    lines.push(`  --shadow-${token.name.replace(/^shadow-/, '')}: ${token.value};`);
}

const css = [
    '/* GERADO por scripts/build-theme.js a partir de design/design-system/tokens.json.',
    '   Não edite à mão: rode `npm run build:theme`. */',
    '@theme {',
    ...lines,
    '}',
    '',
].join('\n');

fs.mkdirSync(path.dirname(outFile), { recursive: true });
fs.writeFileSync(outFile, css);
console.log(`build-theme: ${path.relative(root, outFile)} gerado (${tokens.color.tokens.length} cores).`);
