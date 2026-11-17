<?php

namespace App\Libraries;

/**
 * Catálogo de permissões do sistema. O nome de cada uma diz onde ela aparece no menu lateral
 * ("Grupo › Item: ação"); as chaves seguem o módulo (grupo do menu) e são identificadores técnicos.
 *
 * Duas famílias:
 * - com nível (leitor < editor): <módulo>.acesso e as telas do grupo Acadêmico que têm regra própria (NIVELADAS);
 * - específicas (ESPECIFICAS): conceder = poder executar a ação (sempre 'editor').
 */
final class CatalogoPermissoes
{
    /** @var array<string, string> chave => nome */
    public const ACESSO = [
        'institucional.acesso' => 'Institucional › Campus (prédios e ambientes) e Categorias',
        'academico.acesso'     => 'Acadêmico › Cursos, Disciplinas e Semestres',
        'patrimonio.acesso'    => 'Patrimônio › Itens, Movimentações, Descartes, Modelos, Categorias, '
            . 'Almoxarifados e Softwares',
        'agendamento.acesso'   => 'Agenda › Agenda, Reservas, Alocações, Bloqueios e Feriados',
        'chamados.acesso'      => 'Manutenção › Chamados',
        'usuarios.acesso'      => 'Administração › Usuários',
        'notificacoes.acesso'  => 'Notificações',
        'relatorios.acesso'    => 'Relatórios',
        'auditoria.acesso'     => 'Administração › Log de auditoria',
    ];

    /**
     * Permissões com nível que não são <módulo>.acesso: telas do grupo Acadêmico com regra própria.
     *
     * @var array<string, string> chave => nome
     */
    public const NIVELADAS = [
        'academico.atribuicoes' => 'Acadêmico › Atribuições de aulas (editor gere as de qualquer curso)',
        'academico.requisitos'  => 'Acadêmico › Requisitos de disciplina (editor mantém os próprios)',
    ];

    /** @var list<array{0: string, 1: string}> chave, nome */
    public const ESPECIFICAS = [
        ['agendamento.autorizar_conflito', 'Agenda › Conflitos: autorizar alocação em conflito'],
        ['agendamento.reservar_em_nome_de_terceiros', 'Agenda › Reservas: reservar em nome de terceiros'],
        ['agendamento.cancelar_reserva_de_terceiros', 'Agenda › Reservas: cancelar reservas de outros usuários'],
        ['agendamento.alocar', 'Agenda › Alocações semestrais: criar, cancelar e excluir'],
        ['agendamento.confirmar_reserva', 'Agenda › Confirmações: confirmar reservas pendentes'],
        ['patrimonio.confirmar_recebimento', 'Patrimônio › Movimentações: confirmar recebimento'],
        ['patrimonio.aprovar_descarte', 'Patrimônio › Descartes: aprovar ou rejeitar'],
        ['patrimonio.aprovar_software', 'Patrimônio › Softwares propostos: aprovar, reprovar ou mesclar'],
        ['chamados.resolver_cancelar', 'Manutenção › Chamados: resolver ou cancelar'],
        ['usuarios.trocar_senha', 'Administração › Usuários: trocar a senha de um usuário'],
        ['auditoria.consultar', 'Administração › Log de auditoria: consultar'],
        ['auditoria.expurgar', 'Administração › Log de auditoria: expurgar (superusuário)'],
    ];

    /** A permissão tem nível (leitor/editor) em vez de ser um simples sim/não? */
    public static function temNivel(string $chave): bool
    {
        return str_ends_with($chave, '.acesso') || isset(self::NIVELADAS[$chave]);
    }
}
