<?php
/**
 * Categorias de livros no painel (Acervo → Categorias).
 *
 * - Com o MySQL: dados reais da tabela categorias; gravações em Biblioteca\Services\CategoriaService
 *   (validação, prevenção de duplicidade, transação e registro da ação).
 * - Sem o MySQL (modo demonstrativo): as categorias fictícias de acervo-demo.php, só para consulta.
 *   Nenhum envio grava nada nesse modo.
 * - Permissão para cadastrar, editar, desativar e reativar: categorias.gerenciar (conferida no servidor).
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php';

use Biblioteca\Services\CategoriaService;

function categorias_mysql(): bool
{
    return acervo_usa_mysql();
}

function categorias_servico(): CategoriaService
{
    static $s = null;
    return $s ??= new CategoriaService(db());
}

/** Filtros da listagem lidos de $_GET (somente valores conhecidos). */
function categorias_filtros(array $get): array
{
    $porPagina = (int)admin_param($get, 'por_pagina', '25');
    $situacao = admin_param($get, 'situacao', 'ativas');
    $ordem = admin_param($get, 'ordem', 'nome');
    return [
        'q' => mb_substr(admin_param($get, 'q'), 0, 100),
        'situacao' => in_array($situacao, ['ativas', 'inativas', 'todas'], true) ? $situacao : 'ativas',
        'ordem' => isset(CategoriaService::ORDENS[$ordem]) ? $ordem : 'nome',
        'dir' => admin_param($get, 'dir') === 'desc' ? 'desc' : 'asc',
        'por_pagina' => in_array($porPagina, [25, 50, 100], true) ? (string)$porPagina : '25',
    ];
}

/** Categorias fictícias do modo demonstrativo, no mesmo formato de CategoriaService::listar(). */
function categorias_demo(array $f): array
{
    $itens = [];
    foreach (acervo_categorias() as $c) {
        if ($f['q'] !== '' && !str_contains(acervo_texto_busca($c['nome'], (string)$c['descricao']), acervo_texto_busca($f['q']))) {
            continue;
        }
        $itens[] = ['id' => (int)$c['id'], 'nome' => $c['nome'], 'descricao' => $c['descricao'], 'arquivado_em' => null, 'livros' => (int)$c['total_titulos']];
    }
    $dir = $f['dir'] === 'desc' ? -1 : 1;
    usort($itens, fn ($a, $b) => $dir * ($f['ordem'] === 'livros' ? $a['livros'] <=> $b['livros'] : strcoll(acervo_texto_busca($a['nome']), acervo_texto_busca($b['nome']))));
    return ['itens' => $itens, 'total' => count($itens), 'pagina' => 1, 'paginas' => 1];
}

/** Registra no log só o tipo do erro e devolve uma mensagem genérica. */
function categorias_erro_tecnico(Throwable $e, string $contexto): string
{
    $codigo = $e instanceof PDOException ? ' ' . ($e->errorInfo[1] ?? $e->getCode()) : '';
    error_log('[biblioteca] Erro em ' . $contexto . ': ' . get_class($e) . $codigo . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    return 'Não foi possível concluir a operação. Nada foi gravado. Tente novamente; se o problema continuar, verifique se o banco de dados está ligado.';
}
