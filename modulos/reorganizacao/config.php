<?php
/**
 * CONFIGURAÇÃO CENTRAL do módulo temporário de reorganização do acervo.
 *
 * 'ativo' liga ou desliga o MÓDULO inteiro (menu, páginas, botão "Equipe de organização" e rotas
 * temporárias). Desligado, nada é apagado: lotes, registros e histórico continuam no banco, e as
 * obras, exemplares e códigos BDP criados continuam no acervo como qualquer outro.
 * Para desligar sem editar este arquivo, use no .env:  REORGANIZACAO_MODULO_ATIVO=false
 *
 * O PERÍODO de reorganização (o que libera a entrada dos participantes) é ligado e desligado pelos
 * administradores na configuração do inventário: "Ativar organização por equipes" (migração 250).
 *
 * Os tempos ficam aqui para facilitar o ajuste (cada um aceita também uma variável do .env).
 */
return [
    'ativo' => env_bool('REORGANIZACAO_MODULO_ATIVO', true),

    // Comunicação periódica do navegador do participante com o servidor (segundos).
    'intervalo_sinal' => max(5, (int)env('REORGANIZACAO_INTERVALO_SINAL', '15')),

    // Sem comunicação por este tempo (segundos), a sessão temporária é encerrada (desconectado).
    'tolerancia_desconexao' => max(30, (int)env('REORGANIZACAO_TOLERANCIA', '120')),

    // Reserva de edição de um registro ou exemplar (segundos). Renovada a cada salvamento/sinal.
    'reserva_edicao' => max(30, (int)env('REORGANIZACAO_RESERVA_EDICAO', '90')),

    // Novas sessões por endereço IP por minuto (os Chromebooks da escola costumam sair pelo mesmo IP).
    'limite_entradas_minuto' => max(5, (int)env('REORGANIZACAO_LIMITE_ENTRADAS', '40')),

    // Notificações iguais não se repetem dentro deste intervalo (minutos).
    'intervalo_notificacao_repetida' => 30,

    // Exemplares por registro do Grupo 1 (mesmo limite do "Registrar vários exemplares").
    'max_exemplares_registro' => 50,

    // Exemplares previstos por lote (mesmo limite dos lotes gerenciados).
    'max_exemplares_lote' => 500,
];
