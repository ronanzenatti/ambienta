#!/usr/bin/env node
/**
 * Auditoria de acessibilidade na tela renderizada (Fase 9): axe-core (WCAG 2.0/2.1 A e AA) em cada tela,
 * nos três idiomas, mais uma checagem de teclado (o primeiro Tab cai no "Pular para o conteúdo"; todo
 * elemento que recebe foco tem indicador visível; Esc fecha o modal e devolve o foco a quem o abriu).
 *
 * Não sobe servidor nem mexe em banco: aponta para uma instância já rodando. Uso:
 *   AXE_URL=http://localhost:8081 AXE_EMAIL=... AXE_SENHA=... node scripts/axe.js
 * Variáveis opcionais: AXE_NAVEGADOR (caminho do Chrome/Edge; padrão: Edge do Windows), AXE_IDIOMAS
 * (padrão "pt-BR,en,es"), AXE_FALHAR (impactos que reprovam; padrão "critical,serious").
 * Sai com código 1 se houver violação com impacto em AXE_FALHAR ou falha de teclado; o relatório completo
 * fica em writable/axe/relatorio.json.
 */
const fs = require('node:fs');
const path = require('node:path');
const puppeteer = require('puppeteer-core');

const BASE = (process.env.AXE_URL || 'http://localhost:8080').replace(/\/$/, '');
const EMAIL = process.env.AXE_EMAIL || '';
const SENHA = process.env.AXE_SENHA || '';
const IDIOMAS = (process.env.AXE_IDIOMAS || 'pt-BR,en,es').split(',');
const FALHAR = (process.env.AXE_FALHAR || 'critical,serious').split(',');
const NAVEGADOR = process.env.AXE_NAVEGADOR
    || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const AXE = fs.readFileSync(require.resolve('axe-core/axe.min.js'), 'utf8');

// Telas sem login.
const PUBLICAS = ['/entrar', '/senha/esqueci', '/consulta', '/consulta?texto=a'];

const CAMPUS = ['/campus', /\/campus\/\d+$/];
const PREDIO = /\/predios\/\d+$/;

// Telas com login. [caminho] ou [lista, regex do link de detalhe] (o id sai do primeiro link da lista; a
// lista pode ser outro par desses, para descer campus → prédio → ambiente).
const INTERNAS = [
    '/', '/campus', '/campus/novo', '/categorias-ambiente', '/cursos', '/disciplinas', '/semestres',
    '/usuarios', '/usuarios/novo', '/perfis', '/dominios',
    '/patrimonio/itens', '/patrimonio/estoque', '/patrimonio/movimentacoes', '/patrimonio/movimentacoes/a-receber',
    '/patrimonio/descartes', '/patrimonio/modelos', '/patrimonio/categorias', '/patrimonio/almoxarifados',
    '/patrimonio/softwares', '/patrimonio/numeros',
    '/agendamento/agenda', '/agendamento/reservas', '/agendamento/reservas/novo', '/agendamento/confirmacoes',
    '/agendamento/conflitos', '/agendamento/alocacoes', '/agendamento/alocacoes/novo', '/agendamento/bloqueios',
    '/agendamento/bloqueios/novo', '/agendamento/feriados',
    '/requisitos', '/requisitos/novo', '/chamados', '/chamados/novo',
    '/notificacoes', '/notificacoes/preferencias', '/minha-agenda', '/auditoria', '/relatorios',
    ['/campus', /\/campus\/\d+$/], ['/campus', /\/campus\/\d+\/editar$/], [CAMPUS, PREDIO], [[CAMPUS, PREDIO], /\/ambientes\/\d+$/],
    ['/perfis', /\/perfis\/\d+$/], ['/usuarios', /\/usuarios\/\d+$/],
    ['/patrimonio/itens', /\/patrimonio\/itens\/\d+$/], ['/patrimonio/modelos', /\/patrimonio\/modelos\/\d+$/],
    ['/agendamento/reservas', /\/agendamento\/reservas\/\d+$/],
    ['/agendamento/alocacoes', /\/agendamento\/alocacoes\/\d+$/],
    ['/chamados', /\/chamados\/\d+$/], ['/auditoria', /\/auditoria\/\d+$/], ['/relatorios', /\/relatorios\/[a-z-]+$/],
];

// Telas cujo estado com modal aberto também é auditado: [caminho, seletor do botão que abre].
const MODAIS = [
    ['/cursos', 'a[href$="/cursos/novo"]'],
    ['/cursos', 'button[aria-label^="Excluir"], button[aria-label^="Delete"], button[aria-label^="Eliminar"]'],
];

const falhas = [];
const relatorio = [];

async function abrir(pagina, caminho) {
    const resposta = await pagina.goto(BASE + caminho, { waitUntil: 'networkidle0' });
    return resposta ? resposta.status() : 0;
}

async function auditar(pagina, rotulo) {
    await pagina.evaluate(AXE);
    const resultado = await pagina.evaluate(() => window.axe.run(document, {
        runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] },
    }));
    const violacoes = resultado.violations.map((v) => ({
        id: v.id, impacto: v.impact, ajuda: v.help, alvos: v.nodes.slice(0, 5).map((n) => n.target.join(' ')),
        total: v.nodes.length,
    }));
    // "incomplete": o axe não conseguiu decidir (ex.: contraste sobre imagem/gradiente); vai para revisão manual.
    const revisar = resultado.incomplete.map((v) => ({ id: v.id, alvos: v.nodes.slice(0, 5).map((n) => n.target.join(' ')) }));
    relatorio.push({ tela: rotulo, violacoes, revisar });
    for (const v of violacoes) {
        const reprova = FALHAR.includes(v.impacto);
        if (reprova) falhas.push(`${rotulo}: [${v.impacto}] ${v.id} (${v.total}x) ${v.alvos[0] || ''}`);
        console.log(`  ${reprova ? 'FALHA' : 'aviso'} [${v.impacto}] ${v.id} — ${v.ajuda} (${v.total}x)`);
        v.alvos.forEach((a) => console.log(`        ${a}`));
    }
}

/** Primeiro Tab no skip link; cada parada de foco com indicador visível (outline ou box-shadow). */
async function teclado(pagina, rotulo) {
    // Ponto de partida do Tab no início do documento (a tela pode ter autofocus num campo).
    await pagina.evaluate(() => {
        const inicio = document.createElement('span');
        inicio.tabIndex = -1;
        document.body.prepend(inicio);
        inicio.focus();
        window.scrollTo(0, 0);
    });
    await pagina.keyboard.press('Tab');
    const primeiro = await pagina.evaluate(() => {
        document.body.firstElementChild.tabIndex === -1 && document.body.firstElementChild.remove();
        return document.activeElement.getAttribute('href');
    });
    if (primeiro !== '#conteudo') falhas.push(`${rotulo}: primeiro Tab não foi para "Pular para o conteúdo" (${primeiro})`);

    const semFoco = new Set();
    for (let i = 0; i < 40; i++) {
        await pagina.keyboard.press('Tab');
        const info = await pagina.evaluate(() => {
            const el = document.activeElement;
            if (!el || el === document.body) return null;
            const s = getComputedStyle(el);
            const visivel = (s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) > 0) || s.boxShadow !== 'none';
            return visivel ? null : (el.id ? '#' + el.id : el.tagName.toLowerCase() + '.' + [...el.classList].slice(0, 3).join('.'));
        });
        if (info) semFoco.add(info);
    }
    semFoco.forEach((el) => falhas.push(`${rotulo}: foco sem indicador visível em ${el}`));
}

/** Abre o modal, audita com ele aberto, confere Esc fechando e o foco voltando ao botão. */
async function modal(pagina, caminho, seletor, rotulo) {
    await abrir(pagina, caminho);
    const botao = await pagina.$(seletor);
    if (!botao) { console.log(`  (sem ${seletor} em ${caminho}, pulado)`); return; }
    await botao.focus();
    await pagina.keyboard.press('Enter');
    await pagina.waitForSelector('[role="dialog"]:not([style*="display: none"])', { timeout: 5000 });
    await new Promise((r) => setTimeout(r, 400));
    await auditar(pagina, rotulo);
    await pagina.keyboard.press('Escape');
    await new Promise((r) => setTimeout(r, 400));
    const estado = await pagina.evaluate((sel) => ({
        aberto: [...document.querySelectorAll('[role="dialog"]')].some((d) => d.offsetParent !== null),
        focoVoltou: document.activeElement === document.querySelector(sel),
    }), seletor);
    if (estado.aberto) falhas.push(`${rotulo}: Esc não fechou o modal`);
    if (!estado.focoVoltou) falhas.push(`${rotulo}: foco não voltou ao botão que abriu o modal`);
}

async function trocarIdioma(pagina, idioma) {
    await abrir(pagina, '/consulta');
    await Promise.all([
        pagina.waitForNavigation({ waitUntil: 'networkidle0' }),
        pagina.evaluate((i) => document.querySelector(`#menu-idioma button[value="${i}"]`).click(), idioma),
    ]);
}

async function descobrir(pagina, lista, regex) {
    if (Array.isArray(lista)) lista = await descobrir(pagina, lista[0], lista[1]);
    if (!lista) return null;
    await abrir(pagina, lista);
    const hrefs = await pagina.$$eval('a[href]', (as) => as.map((a) => new URL(a.href).pathname));
    return hrefs.find((h) => regex.test(h)) || null;
}

async function varrer(pagina, telas, idioma) {
    for (const tela of telas) {
        const caminho = Array.isArray(tela) ? await descobrir(pagina, tela[0], tela[1]) : tela;
        if (!caminho) { console.log(`- ${tela[1]} (nenhum registro para abrir, pulado)`); continue; }
        const status = await abrir(pagina, caminho);
        const rotulo = `${idioma} ${caminho}`;
        console.log(`- ${rotulo} (${status})`);
        if (status >= 400) { falhas.push(`${rotulo}: HTTP ${status}`); continue; }
        await auditar(pagina, rotulo);
        await teclado(pagina, rotulo);
    }
}

(async () => {
    const navegador = await puppeteer.launch({ executablePath: NAVEGADOR, headless: true });
    try {
        for (const idioma of IDIOMAS) {
            const contexto = await navegador.createBrowserContext();
            const pagina = await contexto.newPage();
            await pagina.setViewport({ width: 1280, height: 900 });
            await trocarIdioma(pagina, idioma);

            console.log(`\n== ${idioma}: sem login`);
            const idPublico = await descobrir(pagina, '/consulta?texto=a', /\/agenda\/ambiente\/\d+$/);
            await varrer(pagina, idPublico ? [...PUBLICAS, idPublico] : PUBLICAS, idioma);

            if (EMAIL && SENHA) {
                await abrir(pagina, '/entrar');
                await pagina.type('#campo-identificador', EMAIL);
                await pagina.type('#campo-senha', SENHA);
                await Promise.all([pagina.waitForNavigation({ waitUntil: 'networkidle0' }), pagina.keyboard.press('Enter')]);
                await trocarIdioma(pagina, idioma);
                console.log(`\n== ${idioma}: com login`);
                await varrer(pagina, INTERNAS, idioma);
                for (const [caminho, seletor] of MODAIS) {
                    const rotulo = `${idioma} ${caminho} (modal)`;
                    console.log(`- ${rotulo}`);
                    await modal(pagina, caminho, seletor, rotulo);
                }
            } else if (idioma === IDIOMAS[0]) {
                console.log('\n(AXE_EMAIL/AXE_SENHA não definidos: só as telas públicas foram auditadas)');
            }
            await contexto.close();
        }
    } finally {
        await navegador.close();
    }

    const destino = path.join(__dirname, '..', 'writable', 'axe');
    fs.mkdirSync(destino, { recursive: true });
    fs.writeFileSync(path.join(destino, 'relatorio.json'), JSON.stringify({ falhas, relatorio }, null, 2));

    console.log(`\n${falhas.length === 0 ? 'OK' : 'FALHOU'}: ${relatorio.length} telas auditadas, ${falhas.length} falha(s).`);
    falhas.forEach((f) => console.log('  ' + f));
    process.exit(falhas.length === 0 ? 0 : 1);
})().catch((erro) => { console.error(erro); process.exit(2); });
