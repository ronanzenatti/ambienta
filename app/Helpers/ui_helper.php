<?php

/**
 * Componentes base do Design System (README em design/design-system).
 *
 * Cada função renderiza uma view de app/Views/components sem vazar variáveis
 * entre chamadas (saveData = false). Textos de interface vêm dos arquivos de
 * idioma (Ui.php); conteúdo digitado pelo usuário nunca é traduzido.
 */

if (! function_exists('ui_render')) {
    /**
     * @param array<string, mixed> $data
     */
    function ui_render(string $component, array $data): string
    {
        return view('components/' . $component, $data, ['saveData' => false]);
    }
}

if (! function_exists('ui_status_dot')) {
    /**
     * Indicador de status: ponto colorido + rótulo por extenso (nunca só a cor).
     *
     * @param string $tone 'confirmed' | 'pending' | 'critical' | 'neutral' (neutral: estado encerrado, sem sinal)
     */
    function ui_status_dot(string $tone, string $label): string
    {
        if (! in_array($tone, ['confirmed', 'pending', 'critical', 'neutral'], true)) {
            throw new InvalidArgumentException("Tom de status desconhecido: {$tone}");
        }

        return ui_render('status_dot', ['tone' => $tone, 'label' => $label]);
    }
}

if (! function_exists('ui_placa_ambiente')) {
    /**
     * Placa de ambiente, imitando a placa física da porta da sala.
     */
    function ui_placa_ambiente(string $codigo, string $nome): string
    {
        return ui_render('placa_ambiente', ['codigo' => $codigo, 'nome' => $nome]);
    }
}

if (! function_exists('ui_button')) {
    /**
     * Botão do Design System. Com a chave 'href' em $attrs vira um link (<a>).
     *
     * Variantes: 'primary', 'secondary', 'edit' (amarelo, texto ink) e
     * 'delete' (vermelho, texto branco). edit/delete são reservadas a
     * alterar/excluir e não devem ser usadas para outra finalidade.
     *
     * @param array<string, string|bool|null> $attrs Atributos HTML extras (true = booleano, false/null = omitido)
     */
    function ui_button(string $label, string $variant = 'primary', array $attrs = []): string
    {
        ui_classes_botao($variant);

        return ui_render('button', ['label' => $label, 'variant' => $variant, 'attrs' => $attrs]);
    }
}

if (! function_exists('ui_classes_botao')) {
    /**
     * Classes de cor de cada variante de botão (compartilhadas por ui_button e ui_icon_button).
     */
    function ui_classes_botao(string $variant): string
    {
        return match ($variant) {
            'primary'   => 'bg-brand text-brand-ink',
            'secondary' => 'border border-border-strong bg-surface text-ink hover:bg-paper hover:brightness-100',
            'edit'      => 'bg-action-edit text-ink',
            'delete'    => 'bg-signal-critical text-brand-ink',
            default     => throw new InvalidArgumentException("Variante de botão desconhecida: {$variant}"),
        };
    }
}

if (! function_exists('ui_icon_button')) {
    /**
     * Botão só com ícone (coluna Ações das listagens). Exceção ao "rótulo sempre ao lado do ícone": o nome
     * acessível vem de $nomeAcessivel (ex.: "Alterar: Laboratório 3") e a dica visível ($dica) aparece no
     * hover e no foco de teclado, fecha com Esc (WCAG 1.4.13). Com 'href' em $attrs vira link.
     *
     * @param array<string, string|bool|null> $attrs
     */
    function ui_icon_button(
        string $icone,
        string $dica,
        string $nomeAcessivel,
        string $variant,
        array $attrs = []
    ): string {
        return ui_render('icon_button', compact('icone', 'dica', 'nomeAcessivel', 'variant', 'attrs'));
    }
}

if (! function_exists('ui_modal')) {
    /**
     * Modal acessível. Abre com $dispatch('open-modal', '<id>'); fecha só por
     * Cancelar/Fechar ou Esc, nunca por clique no backdrop.
     *
     * $body e $footer são HTML já montado (confiável): escape o que for dado
     * de usuário antes de passar. $confirmWord é usado por ui_confirm_delete().
     * $amplo: painel largo e com rolagem própria, para conteúdo extenso (ex.: agenda semanal de um ambiente).
     */
    function ui_modal(
        string $id,
        string $title,
        string $body,
        string $footer = '',
        ?string $closeLabel = null,
        ?string $confirmWord = null,
        bool $amplo = false
    ): string {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $id) !== 1) {
            throw new InvalidArgumentException("Id de modal inválido: {$id}");
        }

        return ui_render('modal', [
            'id'          => $id,
            'title'       => $title,
            'body'        => $body,
            'footer'      => $footer,
            'closeLabel'  => $closeLabel ?? lang('Ui.close'),
            'confirmWord' => $confirmWord,
            'amplo'       => $amplo,
        ]);
    }
}

if (! function_exists('ui_confirm_delete')) {
    /**
     * Modal de confirmação de exclusão. TODA exclusão do sistema passa por aqui:
     * o botão Excluir começa desabilitado e só habilita ao digitar CONFIRMO.
     * Envia POST com _method=DELETE e o campo 'confirmacao' para $action;
     * o controller deve revalidar que 'confirmacao' === 'CONFIRMO'.
     *
     * @param string      $message Descrição do que será excluído (texto simples, é escapado)
     * @param string|null $button  Rótulo do botão quando a ação não se chama "Excluir" mas apaga (soft delete)
     *                             um registro, ex.: aprovar o descarte de um item
     */
    function ui_confirm_delete(
        string $id,
        string $message,
        string $action,
        ?string $title = null,
        ?string $button = null
    ): string {
        $confirmWord = lang('Ui.confirmWord');
        $formId      = $id . '-form';

        $body = ui_render('confirm_delete_body', [
            'message'     => $message,
            'action'      => $action,
            'formId'      => $formId,
            'fieldId'     => $id . '-confirmacao',
            'confirmWord' => $confirmWord,
        ]);

        $footer = ui_button($button ?? lang('Ui.delete'), 'delete', [
            'type'      => 'submit',
            'form'      => $formId,
            'disabled'  => true,
            ':disabled' => '!canConfirm',
        ]);

        return ui_modal(
            $id,
            $title ?? lang('Ui.confirmDeleteTitle'),
            $body,
            $footer,
            lang('Ui.cancel'),
            $confirmWord
        );
    }
}

if (! function_exists('ui_confirm_delete_linhas')) {
    /**
     * Um modal de exclusão compartilhado pelas linhas de uma listagem (em vez de um por linha): o botão
     * da linha abre com $dispatch('open-modal', { id, acao, mensagem }) e o modal usa a URL e o texto
     * recebidos. Mesmo padrão CONFIRMO de ui_confirm_delete().
     */
    function ui_confirm_delete_linhas(string $id, ?string $title = null): string
    {
        $confirmWord = lang('Ui.confirmWord');
        $formId      = $id . '-form';

        $body = ui_render('confirm_delete_body', [
            'message'     => null,
            'action'      => null,
            'formId'      => $formId,
            'fieldId'     => $id . '-confirmacao',
            'confirmWord' => $confirmWord,
        ]);

        $footer = ui_button(lang('Ui.delete'), 'delete', [
            'type'      => 'submit',
            'form'      => $formId,
            'disabled'  => true,
            ':disabled' => '!canConfirm',
        ]);

        return ui_modal($id, $title ?? lang('Ui.confirmDeleteTitle'), $body, $footer, lang('Ui.cancel'), $confirmWord);
    }
}

if (! function_exists('ui_form_attrs')) {
    /**
     * Atributos do <form> de um formulário curto (cadastro/alteração): na página, o cartão de sempre;
     * no modal da listagem, sem cartão, com o título que o modal mostra (data-titulo).
     */
    function ui_form_attrs(string $acao, string $titulo, bool $modal): string
    {
        $classe = $modal ? '' : 'max-w-2xl rounded-md border border-border bg-surface shadow-xs p-space-6';

        return 'method="post" action="' . esc($acao, 'attr') . '" data-titulo="' . esc($titulo, 'attr') . '"'
            . ($classe === '' ? '' : ' class="' . $classe . '"');
    }
}

if (! function_exists('ui_form_buttons')) {
    /**
     * Salvar + Cancelar de um formulário curto. Salvar é primário ao criar e action-edit ao alterar.
     * Na página, Cancelar volta para $voltar; no modal, só fecha (nada mudou, a tabela não recarrega).
     */
    function ui_form_buttons(bool $novo, string $voltar, bool $modal): string
    {
        $salvar   = ui_button(lang('Ui.save'), $novo ? 'primary' : 'edit', [
            'type'      => 'submit',
            ':disabled' => $modal ? 'enviando' : null,
        ]);
        $cancelar = ui_button(lang('Ui.cancel'), 'secondary', $modal ? ['@click' => 'fechar()'] : ['href' => $voltar]);

        return $modal
            ? '<div class="flex flex-wrap justify-end gap-space-2">' . $cancelar . $salvar . '</div>'
            : '<div class="flex flex-wrap gap-space-3">' . $salvar . $cancelar . '</div>';
    }
}

if (! function_exists('ui_recolhivel_inicio')) {
    /**
     * Acordeão dos dados de um registro, em página de detalhe com listagem embaixo (ex.: campus → prédios):
     * recolhido por padrão, para a listagem ficar à vista. <details> nativo (funciona sem JS e já informa
     * aberto/fechado ao leitor de tela); o aviso ao lado troca entre "exibir" e "ocultar".
     * $aberto: já expandido (ex.: um bloco de ambientes da consulta pública). Feche com ui_recolhivel_fim().
     * $integrado: título e conteúdo formam um cartão só (o conteúdo não leva borda própria nem fica separado do
     * título); $atributos: atributos extras do <details> (ex.: um x-show do Alpine), já escapados.
     */
    function ui_recolhivel_inicio(
        string $titulo,
        bool $aberto = false,
        bool $integrado = false,
        string $atributos = ''
    ): string {
        $cartao = $integrado
            ? 'group mb-space-6 overflow-hidden rounded-md border border-border bg-surface shadow-xs'
            : 'group mb-space-8';
        $resumo = $integrado
            ? 'px-space-6 py-space-4 hover:bg-paper group-open:border-b group-open:border-border'
            : 'rounded-md border border-border bg-surface px-space-6 py-space-4 shadow-xs hover:bg-paper';

        $extra = ($aberto ? ' open' : '') . ($atributos === '' ? '' : ' ' . $atributos);

        return '<details class="' . $cartao . '"' . $extra . '>'
            . '<summary class="flex min-h-11 cursor-pointer list-none flex-wrap items-center justify-between '
            . 'gap-space-4 ' . $resumo . ' '
            . '[&::-webkit-details-marker]:hidden">'
            . '<span class="text-heading">' . esc($titulo) . '</span>'
            . '<span class="inline-flex items-center gap-space-2 text-body text-ink-muted">'
            . '<span class="group-open:hidden">' . esc(lang('Ui.showDetails')) . '</span>'
            . '<span class="hidden group-open:inline">' . esc(lang('Ui.hideDetails')) . '</span>'
            . ui_icon('seta-baixo', 'size-5 transition-transform group-open:rotate-180')
            . '</span></summary><div' . ($integrado ? '>' : ' class="mt-space-6">');
    }
}

if (! function_exists('ui_recolhivel_fim')) {
    function ui_recolhivel_fim(): string
    {
        return '</div></details>';
    }
}

if (! function_exists('ui_field')) {
    /**
     * Campo de formulário: rótulo sempre visível acima, ajuda e erro ligados por aria-describedby.
     * Erro em texto (signal-critical), nunca só a cor.
     *
     * @param array<string, string|bool|null> $attrs Atributos extras do input (required, autocomplete, inputmode...)
     */
    function ui_field(
        string $name,
        string $label,
        string $type = 'text',
        ?string $value = null,
        ?string $error = null,
        ?string $help = null,
        array $attrs = []
    ): string {
        return ui_render('field', compact('name', 'label', 'type', 'value', 'error', 'help', 'attrs'));
    }
}

if (! function_exists('ui_textarea')) {
    /**
     * Texto longo, no mesmo padrão de ui_field().
     *
     * @param array<string, string|bool|null> $attrs
     */
    function ui_textarea(
        string $name,
        string $label,
        ?string $value = null,
        ?string $error = null,
        ?string $help = null,
        int $rows = 3,
        array $attrs = []
    ): string {
        return ui_render('textarea', compact('name', 'label', 'value', 'error', 'help', 'rows', 'attrs'));
    }
}

if (! function_exists('ui_select')) {
    /**
     * Lista de seleção com rótulo e erro, no mesmo padrão de ui_field().
     *
     * @param array<string|int, string>       $options Valor => rótulo
     * @param array<string, string|bool|null> $attrs
     */
    function ui_select(
        string $name,
        string $label,
        array $options,
        string|int|null $selected = null,
        ?string $error = null,
        ?string $help = null,
        array $attrs = []
    ): string {
        return ui_render('select', compact('name', 'label', 'options', 'selected', 'error', 'help', 'attrs'));
    }
}

if (! function_exists('ui_select_fonte')) {
    /**
     * Select cujas opções vêm do banco sob demanda (App\Libraries\Opcoes, rota opcoes/<fonte>). O HTML traz só
     * a opção vazia e a já escolhida (rótulo buscado na própria fonte: valor fora dela não aparece); a lista,
     * a pesquisa e a paginação vêm por fetch ao abrir (exige JS).
     *
     * $params vai na URL da fonte: escopo (chave/nivel da permissão da tela) e filtros fixos.
     * $depende liga a outro campo do formulário: [nome do campo, parâmetro da fonte que recebe o valor dele].
     *
     * @param array<string, int|string|list<string>> $params
     * @param array{0: string, 1: string}|null        $depende
     * @param array<string, string|bool|null>         $attrs
     */
    function ui_select_fonte(
        string $name,
        string $label,
        string $fonte,
        array $params = [],
        int|string|null $valor = null,
        ?string $vazio = null,
        ?string $error = null,
        ?string $help = null,
        ?array $depende = null,
        array $attrs = []
    ): string {
        $options = $vazio === null ? [] : ['' => $vazio];
        $rotulo  = service('opcoes')->porNome($fonte, $params)?->rotuloDe($valor);

        if ($rotulo !== null) {
            $options[(string) $valor] = $rotulo;
        }

        $url    = site_url('opcoes/' . $fonte) . ($params === [] ? '' : '?' . http_build_query($params));
        $attrs += [
            'data-fonte'         => $url,
            'data-depende'       => $depende[0] ?? null,
            'data-depende-param' => $depende[1] ?? null,
        ];

        return ui_select($name, $label, $options, $rotulo === null ? '' : (string) $valor, $error, $help, $attrs);
    }
}

if (! function_exists('ui_file')) {
    /**
     * Envio de arquivo com o arquivo atual (miniatura, ou link se não for imagem) e opção de removê-lo.
     *
     * @param string|null $current URL do arquivo atual
     */
    function ui_file(
        string $name,
        string $label,
        string $accept,
        ?string $current = null,
        bool $currentIsImage = true,
        string $currentAlt = '',
        ?string $error = null,
        ?string $help = null
    ): string {
        return ui_render(
            'file',
            compact('name', 'label', 'accept', 'current', 'currentIsImage', 'currentAlt', 'error', 'help')
        );
    }
}

if (! function_exists('ui_checkbox')) {
    function ui_checkbox(string $name, string $label, bool $checked = false, string $value = '1'): string
    {
        return ui_render('checkbox', compact('name', 'label', 'checked', 'value'));
    }
}

if (! function_exists('ui_alert')) {
    /**
     * Mensagem de retorno (sucesso, aviso, erro): ponto colorido + texto, com papel ARIA adequado.
     *
     * @param string $tone 'confirmed' | 'pending' | 'critical'
     */
    function ui_alert(string $tone, string $message): string
    {
        if (! in_array($tone, ['confirmed', 'pending', 'critical'], true)) {
            throw new InvalidArgumentException("Tom de alerta desconhecido: {$tone}");
        }

        return ui_render('alert', ['tone' => $tone, 'message' => $message]);
    }
}

if (! function_exists('ui_numero')) {
    /**
     * Inteiro com o separador de milhar do idioma atual (1.234 / 1,234). O format_number() do CI4 deixa
     * uma vírgula sobrando com precisão 0 em idiomas de vírgula decimal ("1,"), por isso este.
     */
    function ui_numero(int $numero): string
    {
        $formatador = new NumberFormatter(service('request')->getLocale(), NumberFormatter::DECIMAL);
        $formatador->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 0);

        return (string) $formatador->format($numero);
    }
}

if (! function_exists('ui_icon')) {
    /**
     * Ícone de linha (Lucide, licença ISC), em SVG embutido: traço único, sem preenchimento, cor do texto
     * (currentColor). Decorativo por padrão (aria-hidden): o rótulo por extenso fica sempre ao lado.
     */
    function ui_icon(string $nome, string $classes = 'size-5'): string
    {
        // phpcs:disable Generic.Files.LineLength -- conteúdo SVG copiado do Lucide, sem quebra
        static $icones = [
            'inicio'      => '<path d="M15 21v-8a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v8"/><path d="M3 10a2 2 0 0 1 .709-1.528l7-6a2 2 0 0 1 2.582 0l7 6A2 2 0 0 1 21 10v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
            'busca'       => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
            'predio'      => '<path d="M10 12h4"/><path d="M10 8h4"/><path d="M14 21v-3a2 2 0 0 0-4 0v3"/><path d="M6 10H4a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-2"/><path d="M6 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16"/>',
            'camadas'     => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
            'curso'       => '<path d="M21.42 10.922a1 1 0 0 0-.019-1.838L12.83 5.18a2 2 0 0 0-1.66 0L2.6 9.08a1 1 0 0 0 0 1.832l8.57 3.908a2 2 0 0 0 1.66 0z"/><path d="M22 10v6"/><path d="M6 12.5V16a6 3 0 0 0 12 0v-3.5"/>',
            'livro'       => '<path d="M12 5v16"/><path d="M20.001 19A2 2 0 0022 17V5a2 2 0 00-1.999-2L16 3.002A5 5 0 0012 5a5 5 0 00-4-2H4a2 2 0 00-2 2v12a2 2 0 001.999 2H8a5 5 0 014 2 5 5 0 014-2z"/>',
            'calendario'  => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M16 2v3"/><path d="M3 9h18"/><path d="M8 2v3"/><path d="M17 13h-6"/><path d="M13 17H7"/><path d="M7 13h.01"/><path d="M17 17h.01"/>',
            'usuarios'    => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><circle cx="9" cy="7" r="4"/>',
            'escudo'      => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
            'email'       => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
            'menu'        => '<path d="M4 5h16"/><path d="M4 12h16"/><path d="M4 19h16"/>',
            'painel'      => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M9 3v18"/>',
            'seta-baixo'  => '<path d="m6 9 6 6 6-6"/>',
            'seta-direita' => '<path d="m9 18 6-6-6-6"/>',
            'sair'        => '<path d="m16 17 5-5-5-5"/><path d="M21 12H9"/><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>',
            'idioma'      => '<path d="m5 8 6 6"/><path d="m4 14 6-6 2-3"/><path d="M2 5h12"/><path d="M7 2h1"/><path d="m22 22-5-10-5 10"/><path d="M14 18h6"/>',
            'fechar'      => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
            'usuario'     => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
            'seta-esquerda' => '<path d="m15 18-6-6 6-6"/>',
            'olho'        => '<path d="M2.062 12.348a1 1 0 0 1 0-.696 10.75 10.75 0 0 1 19.876 0 1 1 0 0 1 0 .696 10.75 10.75 0 0 1-19.876 0"/><circle cx="12" cy="12" r="3"/>',
            'lapis'       => '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
            'lixeira'     => '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
            'energia'     => '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
            'download'    => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
            'planilha'    => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M8 13h2"/><path d="M14 13h2"/><path d="M8 17h2"/><path d="M14 17h2"/>',
            'documento'   => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
            'ordem'       => '<path d="m7 15 5 5 5-5"/><path d="m7 9 5-5 5 5"/>',
            'ordem-cresc' => '<path d="m5 12 7-7 7 7"/><path d="M12 19V5"/>',
            'ordem-decr'  => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
            'caixa'       => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="m7.5 4.27 9 5.15"/>',
            'upload'      => '<path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>',
            'check'       => '<path d="M20 6 9 17l-5-5"/>',
            'cadeado'     => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'relogio'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
            'chave'       => '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
            'sino'        => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
            'historico'   => '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l4 2"/>',
        ];
        // phpcs:enable

        if (! isset($icones[$nome])) {
            throw new InvalidArgumentException("Ícone desconhecido: {$nome}");
        }

        return '<svg class="' . esc($classes, 'attr') . ' shrink-0" xmlns="http://www.w3.org/2000/svg"'
            . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round" aria-hidden="true" focusable="false">' . $icones[$nome] . '</svg>';
    }
}
