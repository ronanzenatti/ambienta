<?php

namespace App\Libraries\Tabela;

/**
 * O que a exportação leva: a página visível, tudo o que passa na pesquisa, ou todos os registros
 * (ignorando a pesquisa). O escopo de permissão do usuário vale nas três.
 */
enum Abrangencia: string
{
    case Pagina   = 'pagina';
    case Pesquisa = 'pesquisa';
    case Tudo     = 'tudo';
}
