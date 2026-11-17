<?php

use CodeIgniter\Router\RouteCollection;

/**
 * @var RouteCollection $routes
 */
$routes->get('/', 'Home::index');

// Autenticação (públicas)
$routes->get('entrar', 'Auth::entrar');
$routes->post('entrar', 'Auth::autenticar');
$routes->post('sair', 'Auth::sair');
$routes->post('idioma', 'Auth::idioma');
$routes->get('auth/google', 'Auth::google');
$routes->get('auth/google/callback', 'Auth::googleRetorno');
$routes->get('senha/esqueci', 'Senha::esqueci');
$routes->post('senha/esqueci', 'Senha::enviar');
$routes->get('senha/trocar', 'Senha::trocar', ['filter' => 'auth']);
$routes->post('senha/trocar', 'Senha::salvarTroca', ['filter' => 'auth']);
$routes->get('senha/redefinir/(:segment)', 'Senha::redefinir/$1');
$routes->post('senha/redefinir/(:segment)', 'Senha::salvar/$1');

// Usuários: consulta com usuarios.acesso (leitor); criar, alterar, excluir e atribuir perfis exigem editor.
$routes->group('usuarios', ['filter' => 'permissao:usuarios.acesso'], static function ($routes) {
    $routes->get('', 'Institucional\Usuarios::index');
    $routes->get('exportar', 'Institucional\Usuarios::exportar');
    $routes->get('(:num)', 'Institucional\Usuarios::ver/$1');
});
$routes->group('usuarios', ['filter' => 'permissao:usuarios.acesso,editor'], static function ($routes) {
    $routes->get('novo', 'Institucional\Usuarios::novo');
    $routes->post('', 'Institucional\Usuarios::criar');
    $routes->post('reativar/(:num)', 'Institucional\Usuarios::reativar/$1');
    $routes->post('(:num)', 'Institucional\Usuarios::atualizar/$1');
    $routes->post('(:num)/perfis', 'Institucional\Usuarios::atribuirPerfil/$1');
    $routes->delete('(:num)/perfis/(:num)', 'Institucional\Usuarios::removerPerfil/$1/$2');
    $routes->delete('(:num)/anonimizar', 'Institucional\Usuarios::anonimizar/$1');
    $routes->delete('(:num)', 'Institucional\Usuarios::excluir/$1');
});

// Troca rápida de senha (modal da lista de usuários): permissão própria, escopo conferido no controller.
$routes->group('usuarios', ['filter' => 'permissao:usuarios.trocar_senha,editor'], static function ($routes) {
    $routes->get('(:num)/senha', 'Institucional\Usuarios::senha/$1');
    $routes->post('(:num)/senha', 'Institucional\Usuarios::salvarSenha/$1');
});

// Perfis (matriz de permissões) e domínios de e-mail do Google: só o superperfil.
$routes->group('perfis', ['filter' => 'superperfil'], static function ($routes) {
    $routes->get('', 'Institucional\Perfis::index');
    $routes->get('exportar', 'Institucional\Perfis::exportar');
    $routes->get('novo', 'Institucional\Perfis::novo');
    $routes->post('', 'Institucional\Perfis::criar');
    $routes->get('(:num)', 'Institucional\Perfis::editar/$1');
    $routes->post('(:num)', 'Institucional\Perfis::atualizar/$1');
    $routes->delete('(:num)', 'Institucional\Perfis::excluir/$1');
});
$routes->group('dominios', ['filter' => 'superperfil'], static function ($routes) {
    $routes->get('', 'Institucional\Dominios::index');
    $routes->get('exportar', 'Institucional\Dominios::exportar');
    $routes->get('novo', 'Institucional\Dominios::novo');
    $routes->post('', 'Institucional\Dominios::criar');
    $routes->post('(:num)/alternar', 'Institucional\Dominios::alternar/$1');
    $routes->delete('(:num)', 'Institucional\Dominios::excluir/$1');
});

// Opções dos selects do banco, sob demanda (JSON). Sem filtro de rota: a permissão de cada lista fica em
// App\Libraries\Opcoes\Registro::porNome (as da consulta pública não exigem login).
$routes->get('opcoes/(:segment)', 'Opcoes::index/$1');

// Consulta pública: sem login.
$routes->get('consulta', 'Consulta::index');
$routes->get('consulta/turnos', 'Consulta::turnos');
$routes->get('consulta/resumo', 'Consulta::resumo');

// Agenda pública do ambiente (destino do QR Code) e feeds iCal: sem login. O feed pessoal é protegido pelo
// segredo da URL (usuarios.token_ical), gerado em "Minha agenda no calendário".
$routes->get('agenda/ambiente/(:num)', 'AgendaPublica::ambiente/$1');
$routes->get('agenda/ambiente/(:num)/agenda.ics', 'AgendaPublica::icalAmbiente/$1');
$routes->get('agenda/ambiente/(:num)/qrcode.svg', 'AgendaPublica::qrcode/$1');
$routes->get('agenda/feed/(:alphanum)/agenda.ics', 'AgendaPublica::icalUsuario/$1');
$routes->get('minha-agenda', 'MinhaAgenda::index', ['filter' => 'auth']);
$routes->post('minha-agenda/link', 'MinhaAgenda::gerarLink', ['filter' => 'auth']);

// Estrutura institucional (institucional.acesso). O filtro só exige a permissão em algum escopo: leitura
// para consultar, editor para formulários e gravações. Campus/prédio/ambiente conferem o escopo do
// registro no controller (ver ControladorInstitucional); os catálogos globais valem em qualquer escopo.
$routes->group('', ['filter' => 'permissao:institucional.acesso'], static function ($routes) {
    $routes->get('campus', 'Institucional\Campus::index');
    $routes->get('campus/exportar', 'Institucional\Campus::exportar');
    $routes->get('campus/(:num)', 'Institucional\Campus::ver/$1');
    $routes->get('campus/(:num)/predios/exportar', 'Institucional\Campus::exportarPredios/$1');
    // Listagens gerais (itens "Prédios" e "Ambientes" do menu), além da navegação Campus > Prédio > Ambiente.
    $routes->get('predios', 'Institucional\Predios::index');
    $routes->get('predios/exportar', 'Institucional\Predios::exportar');
    $routes->get('predios/(:num)', 'Institucional\Predios::ver/$1');
    $routes->get('predios/(:num)/ambientes/exportar', 'Institucional\Predios::exportarAmbientes/$1');
    $routes->get('ambientes', 'Institucional\Ambientes::index');
    $routes->get('ambientes/exportar', 'Institucional\Ambientes::exportar');
    $routes->get('ambientes/(:num)', 'Institucional\Ambientes::ver/$1');
    // Tabelas dos cartões da página do ambiente (softwares e itens): o controller confere o escopo.
    $routes->get('ambientes/(:num)/softwares', 'Patrimonio\AmbienteConteudo::softwares/$1');
    $routes->get('ambientes/(:num)/softwares/exportar', 'Patrimonio\AmbienteConteudo::exportarSoftwares/$1');
    $routes->get('ambientes/(:num)/itens', 'Patrimonio\AmbienteConteudo::itens/$1');
    $routes->get('ambientes/(:num)/itens/exportar', 'Patrimonio\AmbienteConteudo::exportarItens/$1');
    $routes->get('categorias-ambiente', 'Institucional\Categorias::index');
    $routes->get('categorias-ambiente/exportar', 'Institucional\Categorias::exportar');
});
$routes->group('', ['filter' => 'permissao:institucional.acesso,editor'], static function ($routes) {
    $routes->get('campus/novo', 'Institucional\Campus::novo');
    $routes->post('campus', 'Institucional\Campus::criar');
    $routes->get('campus/(:num)/editar', 'Institucional\Campus::editar/$1');
    $routes->post('campus/(:num)', 'Institucional\Campus::atualizar/$1');
    $routes->delete('campus/(:num)', 'Institucional\Campus::excluir/$1');
    $routes->get('campus/(:num)/configuracoes', 'Institucional\ConfiguracoesCampus::editar/$1');
    $routes->post('campus/(:num)/configuracoes', 'Institucional\ConfiguracoesCampus::salvar/$1');

    $routes->get('campus/(:num)/predios/novo', 'Institucional\Predios::novo/$1');
    $routes->post('campus/(:num)/predios', 'Institucional\Predios::criar/$1');
    $routes->get('predios/(:num)/editar', 'Institucional\Predios::editar/$1');
    $routes->post('predios/(:num)', 'Institucional\Predios::atualizar/$1');
    $routes->delete('predios/(:num)', 'Institucional\Predios::excluir/$1');

    // Destino do modal de escolha dos botões "Novo prédio" / "Novo ambiente" das listagens gerais.
    $routes->get('predios/novo', 'Institucional\Predios::escolherCampus');
    $routes->get('ambientes/novo', 'Institucional\Ambientes::escolherPredio');
    $routes->get('predios/(:num)/ambientes/novo', 'Institucional\Ambientes::novo/$1');
    $routes->post('predios/(:num)/ambientes', 'Institucional\Ambientes::criar/$1');
    $routes->get('ambientes/(:num)/editar', 'Institucional\Ambientes::editar/$1');
    $routes->post('ambientes/(:num)', 'Institucional\Ambientes::atualizar/$1');
    $routes->delete('ambientes/(:num)', 'Institucional\Ambientes::excluir/$1');

    $routes->get('categorias-ambiente/novo', 'Institucional\Categorias::novo');
    $routes->post('categorias-ambiente', 'Institucional\Categorias::criar');
    $routes->get('categorias-ambiente/(:num)/editar', 'Institucional\Categorias::editar/$1');
    $routes->post('categorias-ambiente/(:num)', 'Institucional\Categorias::atualizar/$1');
    $routes->delete('categorias-ambiente/(:num)', 'Institucional\Categorias::excluir/$1');

});

// Patrimônio e almoxarifado (patrimonio.acesso). Como no institucional, o filtro só exige a permissão em
// algum escopo; o escopo do registro (local do item, campus do almoxarifado) e as permissões específicas
// (confirmar recebimento, aprovar descarte) são conferidos no controller (ver ControladorPatrimonio).
$routes->group('patrimonio', ['filter' => 'permissao:patrimonio.acesso'], static function ($routes) {
    $routes->get('itens', 'Patrimonio\Itens::index');
    $routes->get('itens/exportar', 'Patrimonio\Itens::exportar');
    $routes->get('itens/(:num)', 'Patrimonio\Itens::ver/$1');
    $routes->get('itens/(:num)/movimentacoes/exportar', 'Patrimonio\Itens::exportarMovimentacoes/$1');
    $routes->get('movimentacoes', 'Patrimonio\MovimentacoesItens::index');
    $routes->get('movimentacoes/exportar', 'Patrimonio\MovimentacoesItens::exportar');
    $routes->get('movimentacoes/a-receber', 'Patrimonio\MovimentacoesItens::aReceber');
    $routes->get('movimentacoes/a-receber/exportar', 'Patrimonio\MovimentacoesItens::exportarAReceber');
    $routes->get('movimentacoes/(:num)', 'Patrimonio\MovimentacoesItens::ver/$1');
    $routes->get('descartes', 'Patrimonio\DescartesItens::index');
    $routes->get('descartes/exportar', 'Patrimonio\DescartesItens::exportar');
    $routes->get('descartes/(:num)', 'Patrimonio\DescartesItens::ver/$1');
    $routes->get('modelos', 'Patrimonio\Modelos::index');
    $routes->get('modelos/exportar', 'Patrimonio\Modelos::exportar');
    $routes->get('modelos/(:num)', 'Patrimonio\Modelos::ver/$1');
    $routes->get('modelos/(:num)/exportar', 'Patrimonio\Modelos::exportarUnidades/$1');
    $routes->get('categorias', 'Patrimonio\CategoriasItens::index');
    $routes->get('categorias/exportar', 'Patrimonio\CategoriasItens::exportar');
    $routes->get('almoxarifados', 'Patrimonio\Almoxarifados::index');
    $routes->get('almoxarifados/exportar', 'Patrimonio\Almoxarifados::exportar');
    $routes->get('softwares', 'Patrimonio\Softwares::index');
    $routes->get('softwares/exportar', 'Patrimonio\Softwares::exportar');

    // Fila de softwares propostos por professores: permissao especifica, nao o nivel editor.
    $routes->get('softwares/pendentes', 'Patrimonio\SoftwaresFila::index', ['filter' => 'permissao:patrimonio.aprovar_software,editor']);
    $routes->get('softwares/pendentes/exportar', 'Patrimonio\SoftwaresFila::exportar', ['filter' => 'permissao:patrimonio.aprovar_software,editor']);
    $routes->get('softwares/(:num)/revisar', 'Patrimonio\SoftwaresFila::revisar/$1', ['filter' => 'permissao:patrimonio.aprovar_software,editor']);
    $routes->post('softwares/(:num)/aprovar', 'Patrimonio\SoftwaresFila::aprovar/$1', ['filter' => 'permissao:patrimonio.aprovar_software,editor']);
    $routes->post('softwares/(:num)/reprovar', 'Patrimonio\SoftwaresFila::reprovar/$1', ['filter' => 'permissao:patrimonio.aprovar_software,editor']);
    $routes->post('softwares/(:num)/mesclar', 'Patrimonio\SoftwaresFila::mesclar/$1', ['filter' => 'permissao:patrimonio.aprovar_software,editor']);

    // Confirmar recebimento e decidir descarte dependem de permissões específicas, não do nível editor.
    $routes->post('movimentacoes/(:num)/confirmar', 'Patrimonio\MovimentacoesItens::confirmar/$1', ['filter' => 'permissao:patrimonio.confirmar_recebimento,editor']);
    $routes->post('descartes/(:num)/rejeitar', 'Patrimonio\DescartesItens::rejeitar/$1', ['filter' => 'permissao:patrimonio.aprovar_descarte,editor']);
    $routes->delete('descartes/(:num)/aprovar', 'Patrimonio\DescartesItens::aprovar/$1', ['filter' => 'permissao:patrimonio.aprovar_descarte,editor']);

    // Cancelar o próprio envio: o remetente pode, mesmo sem ser editor na origem (o controller confere).
    $routes->delete('movimentacoes/(:num)', 'Patrimonio\MovimentacoesItens::cancelar/$1', ['filter' => 'permissao:patrimonio.acesso,leitor']);
});
$routes->group('patrimonio', ['filter' => 'permissao:patrimonio.acesso,editor'], static function ($routes) {
    $routes->get('itens/novo', 'Patrimonio\Itens::novo');
    $routes->post('itens', 'Patrimonio\Itens::criar');
    $routes->get('itens/(:num)/editar', 'Patrimonio\Itens::editar/$1');
    $routes->post('itens/(:num)', 'Patrimonio\Itens::atualizar/$1');
    $routes->get('itens/(:num)/movimentar', 'Patrimonio\Itens::movimentar/$1');
    $routes->post('itens/(:num)/movimentar', 'Patrimonio\Itens::enviar/$1');
    $routes->get('itens/(:num)/descartar', 'Patrimonio\Itens::descartar/$1');
    $routes->post('itens/(:num)/descartar', 'Patrimonio\Itens::solicitarDescarte/$1');

    $routes->get('numeros', 'Patrimonio\Numeros::index');
    $routes->get('numeros/exportar', 'Patrimonio\Numeros::exportar');
    $routes->post('numeros/importar', 'Patrimonio\Numeros::importar');
    $routes->get('numeros/modelo', 'Patrimonio\Numeros::planilhaModelo');
    $routes->post('numeros/importar-novos', 'Patrimonio\Numeros::importarNovos');

    $routes->get('modelos/novo', 'Patrimonio\Modelos::novo');
    $routes->post('modelos', 'Patrimonio\Modelos::criar');
    $routes->get('modelos/(:num)/editar', 'Patrimonio\Modelos::editar/$1');
    $routes->post('modelos/(:num)', 'Patrimonio\Modelos::atualizar/$1');
    $routes->delete('modelos/(:num)', 'Patrimonio\Modelos::excluir/$1');
    $routes->post('modelos/(:num)/replicar', 'Patrimonio\Modelos::replicar/$1');

    $routes->get('categorias/novo', 'Patrimonio\CategoriasItens::novo');
    $routes->post('categorias', 'Patrimonio\CategoriasItens::criar');
    $routes->get('categorias/(:num)/editar', 'Patrimonio\CategoriasItens::editar/$1');
    $routes->post('categorias/(:num)', 'Patrimonio\CategoriasItens::atualizar/$1');
    $routes->delete('categorias/(:num)', 'Patrimonio\CategoriasItens::excluir/$1');

    $routes->get('almoxarifados/novo', 'Patrimonio\Almoxarifados::novo');
    $routes->post('almoxarifados', 'Patrimonio\Almoxarifados::criar');
    $routes->get('almoxarifados/(:num)/editar', 'Patrimonio\Almoxarifados::editar/$1');
    $routes->post('almoxarifados/(:num)', 'Patrimonio\Almoxarifados::atualizar/$1');
    $routes->delete('almoxarifados/(:num)', 'Patrimonio\Almoxarifados::excluir/$1');

    $routes->get('softwares/novo', 'Patrimonio\Softwares::novo');
    $routes->post('softwares', 'Patrimonio\Softwares::criar');
    $routes->get('softwares/(:num)/editar', 'Patrimonio\Softwares::editar/$1');
    $routes->post('softwares/(:num)', 'Patrimonio\Softwares::atualizar/$1');
    $routes->delete('softwares/(:num)', 'Patrimonio\Softwares::excluir/$1');
});
// Softwares e itens de um ambiente (cartões da página do ambiente): editor de patrimônio no prédio.
$routes->group('ambientes', ['filter' => 'permissao:patrimonio.acesso,editor'], static function ($routes) {
    $routes->get('(:num)/softwares/novo', 'Patrimonio\AmbienteConteudo::novoSoftware/$1');
    $routes->post('(:num)/softwares', 'Patrimonio\AmbienteConteudo::vincularSoftwares/$1');
    $routes->delete('(:num)/softwares/(:num)', 'Patrimonio\AmbienteConteudo::desvincularSoftware/$1/$2');
    $routes->get('(:num)/itens/novo', 'Patrimonio\AmbienteConteudo::novoItem/$1');
    $routes->post('(:num)/itens', 'Patrimonio\AmbienteConteudo::adicionarItens/$1');
    $routes->delete('(:num)/itens/(:num)', 'Patrimonio\AmbienteConteudo::devolverItem/$1/$2');
});

// Acadêmico: Cursos, Disciplinas e Semestres (academico.acesso; leitura para consultar, editor para gravar).
$routes->group('', ['filter' => 'permissao:academico.acesso'], static function ($routes) {
    $routes->get('cursos', 'Institucional\Cursos::index');
    $routes->get('cursos/exportar', 'Institucional\Cursos::exportar');
    $routes->get('disciplinas', 'Institucional\Disciplinas::index');
    $routes->get('disciplinas/exportar', 'Institucional\Disciplinas::exportar');
    $routes->get('disciplinas/(:num)', 'Institucional\Disciplinas::ver/$1');
    $routes->get('semestres', 'Institucional\Semestres::index');
    $routes->get('semestres/exportar', 'Institucional\Semestres::exportar');
});
$routes->group('', ['filter' => 'permissao:academico.acesso,editor'], static function ($routes) {
    $routes->get('cursos/novo', 'Institucional\Cursos::novo');
    $routes->post('cursos', 'Institucional\Cursos::criar');
    $routes->get('cursos/(:num)/editar', 'Institucional\Cursos::editar/$1');
    $routes->post('cursos/(:num)', 'Institucional\Cursos::atualizar/$1');
    $routes->delete('cursos/(:num)', 'Institucional\Cursos::excluir/$1');

    $routes->get('disciplinas/novo', 'Institucional\Disciplinas::novo');
    $routes->post('disciplinas', 'Institucional\Disciplinas::criar');
    $routes->get('disciplinas/(:num)/editar', 'Institucional\Disciplinas::editar/$1');
    $routes->post('disciplinas/(:num)', 'Institucional\Disciplinas::atualizar/$1');
    $routes->delete('disciplinas/(:num)', 'Institucional\Disciplinas::excluir/$1');

    $routes->get('semestres/novo', 'Institucional\Semestres::novo');
    $routes->post('semestres', 'Institucional\Semestres::criar');
    $routes->get('semestres/(:num)/editar', 'Institucional\Semestres::editar/$1');
    $routes->post('semestres/(:num)', 'Institucional\Semestres::atualizar/$1');
    $routes->delete('semestres/(:num)', 'Institucional\Semestres::excluir/$1');
});

// Acadêmico: Atribuições de aulas (professor + disciplina + semestre). Qualquer usuário logado abre a tela e vê as
// próprias; academico.atribuicoes e a responsabilidade pelo curso decidem o resto (ver Academico\Atribuicoes).
$routes->group('atribuicoes', ['filter' => 'auth'], static function ($routes) {
    $routes->get('', 'Academico\Atribuicoes::index');
    $routes->get('exportar', 'Academico\Atribuicoes::exportar');
    $routes->get('novo', 'Academico\Atribuicoes::novo');
    $routes->get('importar', 'Academico\Atribuicoes::importarForm');
    $routes->post('importar', 'Academico\Atribuicoes::importar');
    $routes->post('', 'Academico\Atribuicoes::criar');
    $routes->delete('(:num)', 'Academico\Atribuicoes::excluir/$1');
    $routes->get('(:num)/requisitos', 'Academico\Atribuicoes::requisitos/$1');
});

// Acadêmico: Requisitos de disciplina (academico.requisitos). O filtro só exige a permissão em algum escopo; quem é
// dono do requisito e chamados.resolver_cancelar são conferidos no controller (ver Chamados\Requisitos).
$routes->group('', ['filter' => 'permissao:academico.requisitos'], static function ($routes) {
    $routes->get('requisitos', 'Chamados\Requisitos::index');
    $routes->get('requisitos/exportar', 'Chamados\Requisitos::exportar');
    $routes->get('requisitos/(:num)', 'Chamados\Requisitos::ver/$1');
    // Tabelas dos cartões da página do requisito (itens e softwares) e a verificação de um ambiente (modal).
    $routes->get('requisitos/(:num)/itens', 'Chamados\Requisitos::itens/$1');
    $routes->get('requisitos/(:num)/itens/exportar', 'Chamados\Requisitos::exportarItens/$1');
    $routes->get('requisitos/(:num)/softwares', 'Chamados\Requisitos::softwares/$1');
    $routes->get('requisitos/(:num)/softwares/exportar', 'Chamados\Requisitos::exportarSoftwares/$1');
    $routes->get('requisitos/(:num)/verificar', 'Chamados\Requisitos::verificar/$1');
});
$routes->group('', ['filter' => 'permissao:academico.requisitos,editor'], static function ($routes) {
    $routes->get('requisitos/novo', 'Chamados\Requisitos::novo');
    $routes->post('requisitos', 'Chamados\Requisitos::criar');
    $routes->get('requisitos/(:num)/editar', 'Chamados\Requisitos::editar/$1');
    $routes->post('requisitos/(:num)', 'Chamados\Requisitos::atualizar/$1');
    $routes->delete('requisitos/(:num)', 'Chamados\Requisitos::excluir/$1');
    $routes->get('requisitos/(:num)/itens/novo', 'Chamados\Requisitos::novoItem/$1');
    $routes->get('requisitos/(:num)/softwares/novo', 'Chamados\Requisitos::novoSoftware/$1');
    $routes->get('requisitos/(:num)/softwares/propor', 'Chamados\Requisitos::novaProposta/$1');
    $routes->post('requisitos/(:num)/itens', 'Chamados\Requisitos::adicionarItem/$1');
    $routes->delete('requisitos/(:num)/itens/(:num)', 'Chamados\Requisitos::removerItem/$1/$2');
    $routes->post('requisitos/(:num)/softwares/propor', 'Chamados\Requisitos::proporSoftware/$1');
    $routes->post('requisitos/(:num)/softwares', 'Chamados\Requisitos::adicionarSoftware/$1');
    $routes->delete('requisitos/(:num)/softwares/(:num)', 'Chamados\Requisitos::removerSoftware/$1/$2');
});

// Manutenção: chamados de adequação (chamados.acesso). O filtro só exige a permissão em algum escopo; o escopo do
// ambiente do chamado e chamados.resolver_cancelar são conferidos no controller (ver ControladorChamados).
$routes->group('', ['filter' => 'permissao:chamados.acesso'], static function ($routes) {
    $routes->get('chamados', 'Chamados\ChamadosAdequacao::index');
    $routes->get('chamados/exportar', 'Chamados\ChamadosAdequacao::exportar');
    $routes->get('chamados/(:num)', 'Chamados\ChamadosAdequacao::ver/$1');
    // Comentar/movimentar: qualquer um que veja o chamado (conferido no controller), sem exigir nível editor.
    $routes->get('chamados/(:num)/movimentacao', 'Chamados\ChamadosAdequacao::movimentacao/$1');
    $routes->post('chamados/(:num)/movimentacao', 'Chamados\ChamadosAdequacao::movimentar/$1');
    $routes->get('ambientes/(:num)/chamados', 'Chamados\ChamadosAdequacao::doAmbiente/$1');
    $routes->get('ambientes/(:num)/chamados/exportar', 'Chamados\ChamadosAdequacao::exportarDoAmbiente/$1');
});
$routes->group('', ['filter' => 'permissao:chamados.acesso,editor'], static function ($routes) {
    $routes->get('chamados/novo', 'Chamados\ChamadosAdequacao::novo');
    $routes->post('chamados', 'Chamados\ChamadosAdequacao::criar');
    $routes->get('chamados/(:num)/situacao', 'Chamados\ChamadosAdequacao::situacao/$1');
    $routes->post('chamados/(:num)/situacao', 'Chamados\ChamadosAdequacao::mudarSituacao/$1');
});

// Alocações e reservas (agendamento.acesso). O filtro só exige leitura em algum escopo; editor no ambiente, as
// permissões específicas (confirmar, autorizar conflito, terceiros) e quem é dono da reserva/alocação são
// conferidos no controller (ver ControladorAgendamento).
$routes->group('agendamento', ['filter' => 'permissao:agendamento.acesso'], static function ($routes) {
    $routes->get('agenda', 'Agendamento\Agenda::index');

    $routes->get('reservas', 'Agendamento\Reservas::index');
    $routes->get('reservas/exportar', 'Agendamento\Reservas::exportar');
    $routes->get('reservas/novo', 'Agendamento\Reservas::novo');
    $routes->post('reservas', 'Agendamento\Reservas::criar');
    $routes->get('reservas/(:num)', 'Agendamento\Reservas::ver/$1');
    $routes->post('reservas/(:num)/confirmar', 'Agendamento\Reservas::confirmar/$1');
    $routes->get('reservas/(:num)/cancelar', 'Agendamento\Reservas::cancelamento/$1');
    $routes->post('reservas/(:num)/cancelar', 'Agendamento\Reservas::cancelar/$1');
    $routes->get('reservas/(:num)/alterar', 'Agendamento\Reservas::edicao/$1');
    $routes->post('reservas/(:num)/alterar', 'Agendamento\Reservas::alterar/$1');
    $routes->post('extras/itens/(:num)', 'Agendamento\Reservas::decidirItem/$1');
    $routes->post('extras/itens/(:num)/devolucao', 'Agendamento\Reservas::devolverItem/$1');
    $routes->post('extras/softwares/(:num)', 'Agendamento\Reservas::decidirSoftware/$1');
    $routes->get('confirmacoes', 'Agendamento\Reservas::fila');
    $routes->get('confirmacoes/exportar', 'Agendamento\Reservas::exportarFila');

    $routes->get('conflitos', 'Agendamento\Conflitos::index');
    $routes->get('conflitos/exportar', 'Agendamento\Conflitos::exportar');
    $routes->post('conflitos/(:num)/autorizar', 'Agendamento\Conflitos::autorizar/$1');
    $routes->get('conflitos/(:num)/negar', 'Agendamento\Conflitos::negacao/$1');
    $routes->post('conflitos/(:num)/negar', 'Agendamento\Conflitos::negarConflito/$1');

    $routes->get('alocacoes', 'Agendamento\Alocacoes::index');
    $routes->get('alocacoes/exportar', 'Agendamento\Alocacoes::exportar');
    $routes->get('alocacoes/novo', 'Agendamento\Alocacoes::novo');
    $routes->post('alocacoes', 'Agendamento\Alocacoes::criar');
    $routes->get('alocacoes/(:num)', 'Agendamento\Alocacoes::ver/$1');
    $routes->delete('alocacoes/(:num)/cancelar', 'Agendamento\Alocacoes::cancelar/$1');
    $routes->delete('alocacoes/(:num)', 'Agendamento\Alocacoes::excluir/$1');

    $routes->get('bloqueios', 'Agendamento\Bloqueios::index');
    $routes->get('bloqueios/exportar', 'Agendamento\Bloqueios::exportar');
    $routes->get('bloqueios/novo', 'Agendamento\Bloqueios::novo');
    $routes->post('bloqueios', 'Agendamento\Bloqueios::criar');
    $routes->delete('bloqueios/(:num)', 'Agendamento\Bloqueios::excluir/$1');

    $routes->get('feriados', 'Agendamento\Feriados::index');
    $routes->get('feriados/exportar', 'Agendamento\Feriados::exportar');
    $routes->get('feriados/novo', 'Agendamento\Feriados::novo');
    $routes->post('feriados', 'Agendamento\Feriados::criar');
    $routes->get('feriados/(:num)/editar', 'Agendamento\Feriados::editar/$1');
    $routes->post('feriados/(:num)', 'Agendamento\Feriados::atualizar/$1');
    $routes->delete('feriados/(:num)', 'Agendamento\Feriados::excluir/$1');
});

// Notificações e preferências: só login (cada usuário vê apenas as suas).
$routes->group('notificacoes', ['filter' => 'auth'], static function ($routes) {
    $routes->get('', 'Notificacoes\Notificacoes::index');
    $routes->get('exportar', 'Notificacoes\Notificacoes::exportar');
    $routes->post('lidas', 'Notificacoes\Notificacoes::marcarTodas');
    $routes->get('preferencias', 'Notificacoes\Notificacoes::preferencias');
    $routes->post('preferencias', 'Notificacoes\Notificacoes::salvarPreferencias');
    $routes->get('(:num)', 'Notificacoes\Notificacoes::abrir/$1');
});

// Log de auditoria: consulta com auditoria.consultar (escopo conferido na consulta); expurgo só o superperfil.
$routes->group('auditoria', ['filter' => 'permissao:auditoria.consultar,editor'], static function ($routes) {
    $routes->get('', 'Notificacoes\Auditoria::index');
    $routes->get('exportar', 'Notificacoes\Auditoria::exportar');
    $routes->get('(:num)', 'Notificacoes\Auditoria::ver/$1');
});
$routes->delete('auditoria/expurgo', 'Notificacoes\Auditoria::expurgar', ['filter' => 'superperfil']);

// Relatórios (REQUISITOS §12): escopo de relatorios.acesso aplicado na consulta de cada relatório.
$routes->group('relatorios', ['filter' => 'permissao:relatorios.acesso'], static function ($routes) {
    $routes->get('', 'Relatorios\Relatorios::index');
    $routes->get('(:segment)', 'Relatorios\Relatorios::ver/$1');
    $routes->get('(:segment)/exportar', 'Relatorios\Relatorios::exportar/$1');
});

// Vitrine dos componentes do Design System: nunca existe em produção (development e testing apenas).
// O POST recebe o formulário de exclusão de demonstração (method spoofing _method=DELETE).
if (in_array(ENVIRONMENT, ['development', 'testing'], true)) {
    $routes->get('_design-system', 'DesignSystem::index');
    $routes->delete('_design-system', 'DesignSystem::index');
}
