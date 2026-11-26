<?php
/**
 * Botão discreto "Equipe de organização" na tela de login do painel (integração do módulo
 * temporário de reorganização). Só aparece com um período de reorganização ATIVO; sem período, com
 * o módulo desligado ou sem a migração 220, não mostra nada. Nenhum erro deste módulo atrapalha o login.
 */
try {
    require_once __DIR__ . '/../bootstrap.php';
    if (reorg_modulo_ativo() && reorg()->periodoAtivo()) {
        $reorgDestino = htmlspecialchars(reorg_url('equipe/'), ENT_QUOTES, 'UTF-8');
        echo '<a class="reorg-botao-equipe" href="' . $reorgDestino . '" title="Entrada da equipe temporária de reorganização do acervo"'
            . ' style="position:fixed;top:12px;right:12px;z-index:50;padding:6px 12px;border:1px solid #c9ced6;border-radius:999px;'
            . 'background:#f3f4f6;color:#5b6472;font-size:.8rem;line-height:1.2;text-decoration:none;font-family:inherit">'
            . 'Equipe de organização</a>';
    }
} catch (Throwable $e) {
    error_log('[reorganizacao] Botão da equipe: ' . $e->getMessage());
}
