<?php

/**
 * Apoio às telas de usuários e perfis (rótulos de vínculo e idiomas).
 */

if (! function_exists('usuarios_rotulo_vinculo')) {
    /**
     * "Perfil · Escopo" de um vínculo de usuarios_perfis (linha com perfil_nome, campus_nome, predio_nome).
     * Nomes de campus, prédio e perfil são conteúdo cadastrado: nunca traduzidos.
     *
     * @param array<string, mixed> $vinculo
     */
    function usuarios_rotulo_vinculo(array $vinculo): string
    {
        $escopo = match ($vinculo['escopo_tipo']) {
            'global' => lang('Usuarios.scopeGlobal'),
            'campus' => lang('Usuarios.scopeCampus', [(string) $vinculo['campus_nome']]),
            default  => lang('Usuarios.scopePredio', [(string) $vinculo['predio_nome']]),
        };

        return $vinculo['perfil_nome'] . ' · ' . $escopo;
    }
}

if (! function_exists('usuarios_idiomas')) {
    /**
     * Idiomas da interface, cada um escrito na própria língua (para o seletor).
     *
     * @return array<string, string>
     */
    function usuarios_idiomas(): array
    {
        return ['pt-BR' => 'Português (Brasil)', 'en' => 'English', 'es' => 'Español'];
    }
}
