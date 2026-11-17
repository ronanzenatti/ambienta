<?php

namespace App\Controllers;

/**
 * Vitrine dos componentes base do Design System.
 * Rota registrada apenas em development/testing, nunca em produção (ver Config/Routes.php).
 */
class DesignSystem extends BaseController
{
    public function index(): string
    {
        return view('design_system', [
            'deleted' => $this->request->is('delete'),
        ]);
    }
}
