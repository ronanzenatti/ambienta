<?php

namespace App\Controllers\Institucional;

/**
 * Base de Cursos, Disciplinas e Semestres, o grupo Acadêmico do menu (permissão academico.acesso; ver
 * Config/Routes.php). Catálogos globais: vale o editor em qualquer escopo, conforme o perfil.
 */
abstract class ControladorAcademico extends ControladorInstitucional
{
    protected const CHAVE = 'academico.acesso';
}
