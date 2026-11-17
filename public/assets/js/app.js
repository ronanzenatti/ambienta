/*
 * JS próprio do Ambienta. Carregado ANTES do Alpine (ordem dos <script defer>
 * em app/Views/layouts/_head.php), para o listener de alpine:init existir a tempo.
 *
 * Copiado para public/assets/js/app.js por `npm run build:assets`.
 */
document.addEventListener('alpine:init', () => {
    /**
     * Select com busca (Tom Select, carregado antes deste arquivo): x-busca no <select>. TODO select do sistema
     * usa (ui_select e o "registros por página" da listagem). Como diretiva, vale também para selects que
     * chegam depois (modal de formulário, região da tabela) e não cria escopo: x-model continua funcionando,
     * porque o Tom Select repassa o "change" para o <select> original.
     *
     * - Opções fixas: a busca filtra no próprio navegador.
     * - data-fonte="<url>": opções do banco sob demanda (App\Libraries\Opcoes). O HTML traz só a opção vazia e
     *   a escolhida; a lista vem ao abrir, 20 por vez, com a pesquisa feita no servidor e mais itens ao rolar.
     * - data-depende="<campo>" (+ data-depende-param): o valor de outro campo do formulário vai na URL da fonte;
     *   quando ele muda, este select é limpo e recarrega.
     * - data-largura: classes de largura do componente (padrão w-full).
     */
    const textosSelect = (() => {
        try {
            return JSON.parse(document.getElementById('ambienta-textos-select')?.textContent ?? '{}');
        } catch {
            return {};
        }
    })();
    const escaparHtml = (texto) => String(texto ?? '').replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));

    window.Alpine.directive('busca', (el, _, { cleanup }) => {
        if (typeof window.TomSelect !== 'function' || el.tomselect) {
            return;
        }

        const fonte = el.dataset.fonte ?? '';
        const depende = el.dataset.depende ?? '';
        const vazia = [...el.options].find((opcao) => opcao.value === '');
        const largura = el.dataset.largura ?? 'w-full';
        const multiplo = el.multiple;
        el.removeAttribute('class');

        const config = {
            plugins: multiplo ? { remove_button: {} } : { dropdown_input: {} },
            maxOptions: null,
            allowEmptyOption: true,
            closeAfterSelect: !multiplo,
            render: {
                no_results: () => `<div class="no-results">${escaparHtml(textosSelect.semResultados)}</div>`,
                loading: () => `<div class="loading">${escaparHtml(textosSelect.carregando)}</div>`,
                loading_more: () => `<div class="loading-more-results">${escaparHtml(textosSelect.carregandoMais)}</div>`,
                no_more_results: () => '',
            },
        };

        if (fonte !== '') {
            const campoPai = () => (depende !== '' ? el.form?.elements.namedItem(depende) : null);
            Object.assign(config, {
                valueField: 'valor',
                labelField: 'rotulo',
                searchField: [],
                score: () => () => 1,
                plugins: multiplo
                    ? { remove_button: {}, virtual_scroll: {} }
                    : { dropdown_input: {}, virtual_scroll: {} },
                preload: 'focus',
                shouldLoad: () => true,
                loadThrottle: 300,
                firstUrl(consulta) {
                    const url = new URL(fonte, window.location.href);
                    url.searchParams.set('q', consulta);
                    const pai = campoPai();
                    if (pai) {
                        url.searchParams.set(el.dataset.dependeParam ?? depende, pai.value ?? '');
                    }
                    return url.toString();
                },
                load(consulta, callback) {
                    fetch(this.getUrl(consulta), {
                        headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                        credentials: 'same-origin',
                    })
                        .then((resposta) => (resposta.ok ? resposta.json() : Promise.reject(resposta.status)))
                        .then((dados) => {
                            if (dados.proxima) {
                                this.setNextUrl(consulta, dados.proxima);
                            }
                            callback(dados.itens ?? []);
                        })
                        .catch(() => callback());
                },
            });
        }

        const ts = new window.TomSelect(el, config);
        if (fonte !== '') {
            // O plugin virtual_scroll sobrescreve o sortField ao iniciar: a opção vazia ("Selecione") volta ao topo aqui.
            ts.settings.sortField = (a, b) => {
                const dado = (resultado) => ts.options[resultado.id] ?? {};
                const vazio = (resultado) => (dado(resultado).valor === '' ? 0 : 1);
                return vazio(a) - vazio(b) || (dado(a).$order ?? 0) - (dado(b).$order ?? 0);
            };
        }
        if (el.closest('[role="dialog"]')) {
            // Em modal, a lista absoluta crescia por trás da rolagem do diálogo: fica fixa na janela, junto ao campo.
            ts.positionDropdown = () => {
                const campo = ts.control.getBoundingClientRect();
                const abaixo = window.innerHeight - campo.bottom - 12;
                const acima = campo.top - 12;
                const sobe = abaixo < 200 && acima > abaixo;
                const espaco = Math.max(120, sobe ? acima : abaixo);
                const lista = ts.dropdown;
                const conteudo = ts.dropdown_content;
                const busca = lista.querySelector('.dropdown-input-wrap')?.offsetHeight ?? 0;
                Object.assign(lista.style, {
                    position: 'fixed',
                    left: `${campo.left}px`,
                    width: `${campo.width}px`,
                    top: sobe ? 'auto' : `${campo.bottom}px`,
                    bottom: sobe ? `${window.innerHeight - campo.top}px` : 'auto',
                });
                conteudo.style.maxHeight = `${Math.min(288, espaco - busca)}px`;
            };
            const reposicionar = () => ts.isOpen && ts.positionDropdown();
            window.addEventListener('resize', reposicionar);
            document.addEventListener('scroll', reposicionar, true);
        }
        ts.wrapper.classList.add(...largura.split(/\s+/).filter(Boolean));
        ts.control_input.setAttribute('placeholder', textosSelect.pesquisar ?? '');

        // Campo do qual a fonte depende mudou: limpa, esquece o que já carregou e recarrega ao abrir.
        const pai = fonte !== '' && depende !== '' ? el.form?.elements.namedItem(depende) : null;
        const aoMudarPai = () => {
            ts.clear(true);
            ts.clearOptions();
            ts.clearPagination?.();
            ts.wrapper.classList.remove('preloaded');
            if (vazia) {
                ts.addOption({ valor: '', rotulo: vazia.textContent.trim() });
                ts.setValue('', true);
            }
        };
        pai?.addEventListener('change', aoMudarPai);

        cleanup(() => {
            pai?.removeEventListener('change', aoMudarPai);
            ts.destroy();
        });
    });

    /**
     * Estado de um modal. Abre por evento: $dispatch('open-modal', '<id>').
     * Nunca fecha por clique no backdrop: só por Cancelar/Fechar ou Esc.
     *
     * confirmWord ativa o padrão de exclusão: `canConfirm` só vira true quando
     * o usuário digita exatamente a palavra (CONFIRMO). O servidor deve
     * revalidar o campo `confirmacao`, isto aqui é apenas conveniência de UI.
     */
    window.Alpine.data('ambientaModal', (id, confirmWord = null) => ({
        id,
        confirmWord,
        open: false,
        typed: '',
        // Dados vindos do evento (modal de exclusão compartilhado de uma listagem: { id, acao, mensagem }).
        dados: {},
        copiado: false,

        get canConfirm() {
            return this.confirmWord !== null && this.typed === this.confirmWord;
        },

        // O evento traz só o id ('excluir-x') ou um objeto com o id e dados extras.
        receber(detalhe) {
            const objeto = typeof detalhe === 'object' && detalhe !== null;
            if ((objeto ? detalhe.id : detalhe) === this.id) {
                this.show(objeto ? detalhe : {});
            }
        },

        show(dados = {}) {
            this.dados = dados;
            this.typed = '';
            this.copiado = false;
            this.open = true;
        },

        close() {
            this.open = false;
            // Nada de senha em claro guardada depois de fechar (modal do resultado da troca de senha).
            if (this.dados.texto !== undefined) {
                this.dados = {};
            }
        },

        // Copia o texto do modal (ex.: acesso do usuário); sem permissão do navegador, o texto segue selecionável.
        async copiar(texto) {
            try {
                await navigator.clipboard.writeText(texto);
                this.copiado = true;
            } catch {
                this.copiado = false;
            }
        },
    }));

    /**
     * Troca rápida de senha (components do formulário de usuários): propõe uma senha forte e aleatória, com
     * letras maiúsculas e minúsculas, número e símbolo, sem caracteres ambíguos (0/O, 1/l/I).
     */
    window.Alpine.data('ambientaSenha', () => ({
        propor() {
            const grupos = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnopqrstuvwxyz', '23456789', '!@#$%&*?'];
            const todos = grupos.join('');
            // Sorteio uniforme (sem viés de módulo) com o gerador criptográfico do navegador.
            const sorteio = (max) => {
                const limite = Math.floor(0x100000000 / max) * max;
                const valor = new Uint32Array(1);
                do {
                    crypto.getRandomValues(valor);
                } while (valor[0] >= limite);
                return valor[0] % max;
            };
            const letras = grupos.map((grupo) => grupo[sorteio(grupo.length)]);
            while (letras.length < 16) {
                letras.push(todos[sorteio(todos.length)]);
            }
            for (let i = letras.length - 1; i > 0; i--) {
                const j = sorteio(i + 1);
                [letras[i], letras[j]] = [letras[j], letras[i]];
            }
            this.$refs.senha.value = letras.join('');
            this.$refs.senha.focus();
        },
    }));

    /**
     * Listagem padrão (app/Views/components/tabela). Sem JS tudo funciona por GET comum; aqui a busca
     * (enquanto digita), os registros por página, a ordenação e a paginação buscam só a região da tabela
     * (?parcial=1) e a trocam, sem recarregar a tela. A URL da página acompanha (replaceState), para
     * recarregar, voltar e o menu Exportar usarem o mesmo estado.
     */
    window.Alpine.data('ambientaTabela', (porPadrao, alvo = null) => ({
        carregando: false,
        erro: false,
        anuncio: '',
        sucesso: '',
        controle: null,

        init() {
            this.$refs.regiao.addEventListener('click', (evento) => {
                const link = evento.target.closest('a[data-tabela-link]');
                if (!link || evento.button !== 0 || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey) {
                    return;
                }
                evento.preventDefault();
                this.carregar(new URL(link.href, window.location.href), link.dataset.foco ?? null);
            });
        },

        pesquisar() {
            this.carregar(new URL(this.$refs.form.action, window.location.href), null);
        },

        // Depois de salvar um formulário no modal: mesma busca, ordem e página, com a mensagem do servidor.
        // Tabela independente (alvo): várias na mesma tela, cada uma com a sua URL; só recarrega quando o
        // evento é dela, e a URL da página não acompanha a busca, a ordem nem a página.
        recarregar(detalhe) {
            if ((detalhe?.tabela ?? null) !== alvo) {
                return;
            }
            if (alvo === null) {
                this.carregar(new URL(window.location.href), null, true);
            } else {
                this.carregar(new URL(this.$refs.form.action, window.location.href), null);
            }
            this.sucesso = detalhe?.mensagem ?? '';
        },

        // Busca e registros por página vêm sempre do formulário (a pessoa pode ter digitado depois de o link
        // ser gerado); ordem e página vêm do link (ou do formulário, numa nova busca, que volta à página 1).
        // manter = recarga do estado atual (URL da página), sem mexer em parâmetros nem no foco.
        async carregar(url, foco, manter = false) {
            const form = this.$refs.form;
            if (!manter) {
                this.sucesso = '';
                const campos = foco === null ? ['q', 'por', 'ordem', 'dir'] : ['q', 'por'];
                for (const campo of campos) {
                    const valor = form.elements[campo]?.value ?? '';
                    const padrao = campo === 'por' && valor === String(porPadrao);
                    valor === '' || padrao ? url.searchParams.delete(campo) : url.searchParams.set(campo, valor);
                }
                if (foco === null) {
                    url.searchParams.delete('pagina');
                }
                // Filtros próprios da tela (ex.: período de um relatório) acompanham toda recarga.
                for (const filtro of form.querySelectorAll('input[data-filtro]')) {
                    url.searchParams.set(filtro.name, filtro.value);
                }
            }

            const pedido = new URL(url);
            pedido.searchParams.set('parcial', '1');

            this.controle?.abort();
            this.controle = new AbortController();
            this.carregando = true;
            this.erro = false;

            try {
                const resposta = await fetch(pedido, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    credentials: 'same-origin',
                    signal: this.controle.signal,
                });

                // Sessão expirada ou permissão perdida: a navegação normal mostra o que o servidor decidir.
                if (resposta.redirected) {
                    window.location.href = url.toString();
                    return;
                }
                if (!resposta.ok) {
                    throw new Error(String(resposta.status));
                }

                this.$refs.regiao.innerHTML = await resposta.text();
                if (alvo === null) {
                    window.history.replaceState(window.history.state, '', url.pathname + url.search + url.hash);
                }

                form.elements.ordem.value = url.searchParams.get('ordem') ?? '';
                form.elements.dir.value = url.searchParams.get('dir') ?? '';
                this.anuncio = this.$refs.regiao.querySelector('[data-resumo]')?.dataset.resumo ?? '';
                this.carregando = false;

                // Quem clicou num link (ordenar, página) não perde o lugar: o foco vai ao equivalente novo.
                if (foco !== null) {
                    const alvo = this.$refs.regiao.querySelector(`[data-foco="${CSS.escape(foco)}"]`) ?? this.$refs.regiao;
                    alvo.focus({ preventScroll: true });
                    this.$refs.regiao.scrollIntoView({ block: 'nearest' });
                }
            } catch (falha) {
                if (falha.name === 'AbortError') {
                    return;
                }
                this.erro = true;
                this.carregando = false;
            }
        },
    }));

    /**
     * O token CSRF é regenerado a cada POST: depois de um envio por fetch, o servidor manda o novo no
     * cabeçalho (BaseController::comTokenCsrf) e todos os formulários da tela passam a usá-lo.
     */
    const atualizarCsrf = (resposta) => {
        const nome = resposta.headers.get('X-Csrf-Name');
        const valor = resposta.headers.get('X-Csrf-Hash');
        if (!nome || !valor) {
            return;
        }
        for (const campo of document.querySelectorAll(`input[name="${CSS.escape(nome)}"]`)) {
            campo.value = valor;
        }
    };

    /**
     * Formulários curtos (cadastrar/alterar) abertos no modal da listagem (components/tabela/formulario_modal).
     * Ligado ao @click do link "Novo …"/"Alterar": sem JS, ou com Ctrl/Shift/clique do meio, o link abre a
     * página do formulário normalmente.
     */
    window.ambientaAbrirFormulario = (evento) => {
        if (evento.button !== 0 || evento.ctrlKey || evento.metaKey || evento.shiftKey || evento.altKey) {
            return;
        }
        evento.preventDefault();
        window.dispatchEvent(new CustomEvent('abrir-formulario', { detail: evento.currentTarget.href }));
    };

    window.Alpine.data('ambientaFormulario', () => ({
        open: false,
        titulo: '',
        carregando: false,
        enviando: false,
        erro: false,

        comModal(endereco) {
            const url = new URL(endereco, window.location.href);
            url.searchParams.set('modal', '1');
            return url;
        },

        async abrir(endereco) {
            this.$refs.corpo.innerHTML = '';
            this.titulo = '';
            this.erro = false;
            this.open = true;
            this.carregando = true;

            try {
                const resposta = await fetch(this.comModal(endereco), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    credentials: 'same-origin',
                });
                // Sessão expirada, sem permissão ou registro não encontrado: a página do formulário decide.
                if (resposta.redirected || !resposta.ok) {
                    window.location.href = endereco;
                    return;
                }
                this.mostrar(await resposta.text());
            } catch {
                this.erro = true;
            } finally {
                this.carregando = false;
            }
        },

        // Formulário vindo do servidor; o foco vai ao primeiro campo com erro, ou ao primeiro campo.
        mostrar(html) {
            this.$refs.corpo.innerHTML = html;
            this.titulo = this.$refs.corpo.querySelector('form')?.dataset.titulo ?? '';
            this.$nextTick(() => {
                const corpo = this.$refs.corpo;
                (corpo.querySelector('[aria-invalid="true"]')
                    ?? corpo.querySelector('input:not([type="hidden"]), select, textarea'))?.focus();
            });
        },

        async enviar(form) {
            if (this.enviando) {
                return;
            }
            this.enviando = true;
            this.erro = false;

            try {
                const resposta = await fetch(this.comModal(form.action), {
                    method: 'POST',
                    body: new FormData(form),
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json, text/html' },
                    credentials: 'same-origin',
                });
                atualizarCsrf(resposta);

                if (resposta.redirected) {
                    window.location.reload();
                    return;
                }
                if (resposta.status === 422) {
                    this.mostrar(await resposta.text());
                    return;
                }
                if (!resposta.ok) {
                    throw new Error(String(resposta.status));
                }

                const dados = await resposta.json();
                // Registro recém-criado: segue para a página dele (a mensagem vai pela sessão).
                if (dados.redirecionar) {
                    window.location.href = dados.redirecionar;
                    return;
                }
                // Resultado a mostrar em outro modal (ex.: texto de acesso após trocar a senha).
                if (dados.resultado) {
                    this.fechar();
                    window.dispatchEvent(new CustomEvent('open-modal', {
                        detail: { id: 'senha-resultado', ...dados.resultado },
                    }));
                    return;
                }
                this.fechar();
                window.dispatchEvent(new CustomEvent('tabela-recarregar', {
                    detail: { mensagem: dados.mensagem ?? '', tabela: form.dataset.tabela ?? null },
                }));
            } catch {
                this.erro = true;
            } finally {
                this.enviando = false;
            }
        },

        // Fechar sem salvar não recarrega a tabela: nada mudou.
        fechar() {
            this.open = false;
            this.$refs.corpo.innerHTML = '';
        },
    }));

    /**
     * Verificar um ambiente (modal da página do requisito): busca o resultado da verificação e o mostra no
     * próprio modal, sem recarregar a página nem fechar o modal.
     */
    window.Alpine.data('ambientaVerificacao', () => ({
        carregando: false,
        erro: false,

        async verificar(form) {
            const ambiente = form.elements.ambiente_id?.value ?? '';
            if (ambiente === '') {
                return;
            }
            this.carregando = true;
            this.erro = false;

            try {
                const url = new URL(form.action, window.location.href);
                url.searchParams.set('ambiente_id', ambiente);
                const resposta = await fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' },
                    credentials: 'same-origin',
                });
                if (resposta.redirected || !resposta.ok) {
                    throw new Error(String(resposta.status));
                }
                this.$refs.resultado.innerHTML = await resposta.text();
            } catch {
                this.erro = true;
            } finally {
                this.carregando = false;
            }
        },
    }));

    /**
     * Nova reserva em abas (app/Views/agendamento/reservas/form.php). Avançar exige o requisito da aba atual
     * (busca feita, ambiente selecionado, título e repetição válidos); voltar é sempre livre. A aba 1 é um GET
     * que traz os ambientes do horário (a página recarrega já na aba 2); as demais são um só POST, enviado depois
     * da confirmação no resumo. O servidor revalida tudo: isto é conveniência de tela.
     */
    window.Alpine.data('ambientaReserva', (config) => ({
        passo: config.passoInicial,
        buscado: config.buscado,
        disciplina: config.disciplina,
        ambientes: config.ambientes,
        ambienteId: config.ambienteId,
        titulo: config.titulo,
        ignorar: config.ignorar,
        itens: config.itens,
        softwares: config.softwares,
        t: config.textos,
        filtro: '',
        verId: '',
        erro: '',
        erroExtra: '',
        res: {},
        confirmado: false,

        init() {
            if (!(this.ambienteId in this.ambientes)) {
                this.ambienteId = '';
            }
            if (this.ignorarDesativado) {
                this.ignorar = false;
            }
            if (this.passo === 5) {
                this.$nextTick(() => this.montarResumo());
            }
        },

        get ambiente() {
            return this.ambientes[this.ambienteId] ?? null;
        },

        get ver() {
            return this.ambientes[this.verId] ?? null;
        },

        // Ambiente que já atende (ou sem disciplina): não há chamado a abrir, então não há o que ignorar.
        get atende() {
            return this.ambiente !== null && (this.ambiente.nivel === '' || this.ambiente.nivel === config.integral);
        },

        get ignorarDesativado() {
            return !this.disciplina || this.ambiente === null || this.atende;
        },

        get ajudaIgnorar() {
            if (!this.disciplina) {
                return this.t.ignoreNoDiscipline;
            }
            return this.atende ? this.t.ignoreMet : this.t.ignoreHelp;
        },

        get avisoAtendimento() {
            if (!this.disciplina || this.ambiente === null || this.atende || this.ignorar) {
                return '';
            }
            return this.t.fulfilmentAsk.replace('{0}', this.ambiente.atendimento);
        },

        normalizar(texto) {
            return String(texto ?? '').normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
        },

        combina(id) {
            const busca = this.normalizar(this.filtro).trim();
            return busca === '' || this.normalizar(this.ambientes[id].busca).includes(busca);
        },

        get visiveis() {
            return Object.keys(this.ambientes).filter((id) => this.combina(id)).length;
        },

        abrirResumo(id) {
            this.verId = String(id);
            this.$dispatch('open-modal', 'reserva-ambiente');
        },

        selecionar(id) {
            this.ambienteId = String(id);
            this.erro = '';
            if (this.ignorarDesativado) {
                this.ignorar = false;
            }
        },

        campo(nome) {
            return this.$refs.form?.elements.namedItem(nome) ?? null;
        },

        valor(nome) {
            return this.campo(nome)?.value ?? '';
        },

        // Texto da opção escolhida num select (Tom Select guarda os rótulos nas próprias opções).
        rotuloDe(select) {
            const ts = select?.tomselect;
            const opcao = ts?.options[select.value];
            if (opcao) {
                return String(opcao[ts.settings.labelField] ?? '').trim();
            }
            return (select?.selectedOptions[0]?.text ?? '').trim();
        },

        mudouBusca() {
            const dados = new FormData(this.$refs.busca);
            return ['data', 'inicio', 'fim', 'disciplina', 'ocupados']
                .some((nome) => String(dados.get(nome) ?? '') !== String(config.busca[nome] ?? ''));
        },

        // Requisito para sair da aba p. Na 1, só avança sem recarregar se a busca não mudou.
        validar(p) {
            this.erro = '';

            if (p === 1) {
                if (this.buscado && !this.mudouBusca()) {
                    return true;
                }
                if (this.$refs.busca.reportValidity()) {
                    this.$refs.busca.submit();
                }
                return false;
            }

            if (p === 2 && this.ambienteId === '') {
                this.erro = this.t.chooseEnvironment;
                return false;
            }

            if (p === 3) {
                if (this.titulo.trim() === '') {
                    this.erro = this.t.titleRequired;
                    document.getElementById('campo-titulo')?.focus();
                    return false;
                }
                const semFim = this.valor('repetir_ate') === '' && !(parseInt(this.valor('repetir_vezes'), 10) >= 2);
                if (this.valor('repetir') !== '' && semFim) {
                    this.erro = this.t.repeatRequired;
                    return false;
                }
            }

            return true;
        },

        ir(n) {
            this.erro = '';
            while (this.passo < n && this.validar(this.passo)) {
                this.passo += 1;
            }
            if (n < this.passo) {
                this.passo = n;
            }
            if (this.passo === 5) {
                this.montarResumo();
            }
            this.$nextTick(() => document.getElementById(`aba-${this.passo}`)?.focus());
        },

        proximo() {
            this.ir(this.passo + 1);
        },

        anterior() {
            this.ir(this.passo - 1);
        },

        montarResumo() {
            const data = (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString(document.documentElement.lang || undefined);
            let repeticao = this.t.repeatNone;
            if (this.valor('repetir') !== '') {
                const ate = this.valor('repetir_ate');
                const fim = ate !== ''
                    ? this.t.summaryUntil.replace('{0}', data(ate))
                    : this.t.summaryTimes.replace('{0}', this.valor('repetir_vezes'));
                repeticao = `${this.rotuloDe(this.campo('repetir'))} · ${fim}`;
            }
            const paraQuem = this.valor('beneficiario_id') !== '' ? this.rotuloDe(this.campo('beneficiario_id')) : this.t.forMyself;
            this.res = { titulo: this.titulo.trim(), paraQuem, repeticao };
        },

        adicionarItem() {
            const select = document.getElementById('campo-extra_categoria');
            const quantidade = document.getElementById('campo-extra_quantidade');
            const qtd = parseInt(quantidade.value, 10);
            if (select.value === '') {
                this.erroExtra = this.t.optionInvalid;
                return false;
            }
            if (!(qtd >= 1 && qtd <= 999)) {
                this.erroExtra = this.t.quantityInvalid;
                return false;
            }
            const existente = this.itens.find((item) => item.id === select.value);
            if (existente) {
                existente.qtd = Math.min(999, existente.qtd + qtd);
            } else {
                this.itens.push({ id: select.value, rotulo: this.rotuloDe(select), qtd });
            }
            select.tomselect?.clear();
            quantidade.value = '1';
            this.erroExtra = '';
            return true;
        },

        adicionarSoftware() {
            const select = document.getElementById('campo-extra_software');
            if (select.value === '') {
                this.erroExtra = this.t.optionInvalid;
                return false;
            }
            if (!this.softwares.some((software) => software.id === select.value)) {
                this.softwares.push({ id: select.value, rotulo: this.rotuloDe(select) });
            }
            select.tomselect?.clear();
            this.erroExtra = '';
            return true;
        },

        removerItem(id) {
            this.itens = this.itens.filter((item) => item.id !== id);
        },

        removerSoftware(id) {
            this.softwares = this.softwares.filter((software) => software.id !== id);
        },

        // Confirmado no modal do resumo: o servidor recebe "ciente" (o aviso de atendimento foi mostrado).
        enviar() {
            this.confirmado = true;
            this.$refs.ciente.value = '1';
            this.$refs.form.requestSubmit();
        },
    }));

    /**
     * Menu Exportar de uma listagem: cada link leva o estado atual da tabela (lido da URL da página).
     */
    window.Alpine.data('ambientaExportar', (base) => ({
        link(formato, abrangencia) {
            const url = new URL(base, window.location.href);
            // Tudo o que está na URL da tela (busca, ordem, página e filtros próprios), menos o que é da exportação.
            for (const [campo, valor] of new URLSearchParams(window.location.search)) {
                if (!['formato', 'abrangencia', 'parcial', 'modal'].includes(campo)) {
                    url.searchParams.set(campo, valor);
                }
            }
            url.searchParams.set('formato', formato);
            url.searchParams.set('abrangencia', abrangencia);
            return url.toString();
        },
    }));

    /**
     * Estado do painel administrativo (layouts/main.php, usuário logado).
     * Tela larga (xl, 1280px+): o menu lateral fica fixo e pode ser recolhido só aos ícones; a escolha
     * fica lembrada neste navegador. Tela estreita: o menu abre por cima do conteúdo (menuAberto).
     */
    const CHAVE_RECOLHIDO = 'ambienta.menuRecolhido';
    const lerPreferencia = () => {
        try {
            return window.localStorage.getItem(CHAVE_RECOLHIDO) === '1';
        } catch {
            return false;
        }
    };
    const gravarPreferencia = (valor) => {
        try {
            window.localStorage.setItem(CHAVE_RECOLHIDO, valor ? '1' : '0');
        } catch {
            // Sem armazenamento (navegação privada, bloqueio): só não lembra a escolha.
        }
    };

    window.Alpine.data('ambientaShell', () => ({
        menuAberto: false,
        recolhido: lerPreferencia(),

        telaLarga() {
            return window.matchMedia('(min-width: 80rem)').matches;
        },

        alternarMenu() {
            if (this.telaLarga()) {
                this.recolhido = !this.recolhido;
                gravarPreferencia(this.recolhido);
            } else {
                this.menuAberto = !this.menuAberto;
            }
        },

        expandir() {
            this.recolhido = false;
            gravarPreferencia(false);
        },
    }));

    /**
     * Consulta pública: clicar num agendamento (botão com data-consulta-evento, na grade ou na agenda semanal de um
     * ambiente) abre o modal de detalhes. Um só ouvinte para todos os blocos. Se o clique veio de dentro do modal da
     * agenda do ambiente, este fecha antes (um modal por vez).
     */
    document.addEventListener('click', (evento) => {
        const bloco = evento.target.closest('[data-consulta-evento]');
        if (bloco === null) {
            return;
        }
        if (bloco.closest('[aria-labelledby="consulta-sala-title"]') !== null) {
            window.dispatchEvent(new CustomEvent('close-modal', { detail: 'consulta-sala' }));
        }
        window.dispatchEvent(new CustomEvent('open-modal', {
            detail: { id: 'consulta-evento', chave: bloco.dataset.consultaEvento },
        }));
    });

    /**
     * Consulta pública, visão linha do tempo: dia da aba e turno escolhidos. O turno é um zoom da régua: a faixa
     * [início, fim] em minutos vira as variáveis CSS --r0 e --rs, de que dependem a posição e a largura de cada
     * bloco (calc em %), então trocar de turno ou de dia não pede nova busca.
     */
    window.Alpine.data('ambientaConsulta', (config) => ({
        dia: config.dia,
        turno: 'todos',

        get estilo() {
            const [inicio, fim] = config.faixas[this.turno];
            return `--r0: ${inicio}; --rs: ${fim - inicio}`;
        },
    }));

    /**
     * Agenda da semana de um ambiente (modal da linha do tempo e linha expandida do resumo por sala), montada do
     * store 'consulta'. `idAmbiente` é o id ou uma função que o devolve (o modal só sabe o id ao abrir).
     */
    window.Alpine.data('ambientaSala', (idAmbiente) => ({
        horas: Array.from({ length: 16 }, (_, i) => 7 + i),

        get tipos() {
            return window.Alpine.store('consulta').tipos;
        },

        get id() {
            return typeof idAmbiente === 'function' ? idAmbiente() : idAmbiente;
        },

        get sala() {
            return window.Alpine.store('consulta').ambientes[this.id] ?? null;
        },

        get dias() {
            const loja = window.Alpine.store('consulta');
            const doAmbiente = loja.grade[this.id] ?? {};
            return loja.dias.map((dia) => ({
                ...dia,
                eventos: (doAmbiente[dia.chave] ?? []).map((chave) => loja.eventos[chave]),
            }));
        },

        // Posição e tamanho pela proporção de minutos sobre a régua de 07h às 23h (960 min); sobrepostos dividem a coluna.
        estilo(e) {
            return `top: ${((e.imin - 420) / 960) * 100}%; height: ${((e.fmin - e.imin) / 960) * 100}%; `
                + `left: ${(e.faixa / e.faixas) * 100}%; width: ${100 / e.faixas}%`;
        },

    }));

    // Consulta pública, resumo por sala: quais salas estão expandidas.
    window.Alpine.data('ambientaResumo', (ids) => ({
        abertos: {},

        alternar(id) {
            this.abertos[id] = !this.abertos[id];
        },

        todos(abrir) {
            this.abertos = Object.fromEntries(ids.map((id) => [id, abrir]));
        },
    }));

    /**
     * Prévia da identidade de um campus: contraste WCAG entre a cor primária e o texto escolhido
     * (claro/escuro, com os valores dos tokens vindos do servidor). É só aviso ao vivo; quem decide se
     * salva é App\Libraries\Contraste, com a mesma conta.
     */
    const hexValido = (cor) => /^#[0-9a-fA-F]{6}$/.test(cor);

    const luminancia = (cor) => {
        const [r, g, b] = [1, 3, 5].map((i) => {
            const c = parseInt(cor.slice(i, i + 2), 16) / 255;
            return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * b;
    };

    window.Alpine.data('identidadeCampus', (config) => ({
        cor: config.cor,
        texto: config.texto,

        get valido() {
            return hexValido(this.cor) && Object.hasOwn(config.cores, this.texto);
        },

        get corSeletor() {
            return hexValido(this.cor) ? this.cor.toLowerCase() : '#000000';
        },

        set corSeletor(valor) {
            this.cor = valor.toUpperCase();
        },

        get corTexto() {
            return config.cores[this.texto] ?? '';
        },

        get razao() {
            if (!this.valido) {
                return 0;
            }
            const a = luminancia(this.cor);
            const b = luminancia(this.corTexto);
            return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
        },

        get atende() {
            return this.razao >= config.minimo;
        },

        // Truncada em 2 casas, como no servidor: nunca mostra "4,50" quando é 4,497.
        get razaoFormatada() {
            return (Math.floor(this.razao * 100) / 100).toFixed(2).replace('.', config.decimal);
        },
    }));
});
