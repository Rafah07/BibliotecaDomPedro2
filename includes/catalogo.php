<?php
/**
 * Ponto de acesso do site público aos dados do catálogo.
 * Com o MySQL configurado, usa o acervo real; sem ele, os exemplos de includes/dados-exemplo.php,
 * sempre identificados como demonstração (ver src/Services/CatalogoService.php).
 */
require_once __DIR__ . '/../config/database.php';

use Biblioteca\Repositories\CatalogoRepository;
use Biblioteca\Services\CatalogoService;

function catalogo(): CatalogoService
{
    static $servico = null;
    if ($servico !== null) {
        return $servico;
    }
    require __DIR__ . '/dados-exemplo.php';
    $exemplos = ['livros' => $livrosExemplo, 'generos' => $generosExemplo, 'autores' => $autoresExemplo];
    try {
        $pdo = db();
        $servico = new CatalogoService($pdo ? new CatalogoRepository($pdo) : null, $exemplos);
    } catch (DbIndisponivel $e) {
        $servico = new CatalogoService(null, $exemplos, true);
    }
    return $servico;
}

/** Aviso exibido no topo das páginas conforme a origem dos dados (ou '' para o acervo real). */
function catalogo_aviso(): string
{
    $mensagens = [
        'demonstracao' => ['info', 'Demonstração: os livros exibidos são exemplos e não fazem parte do acervo da biblioteca.'],
        'vazio' => ['inventory_2', 'O catálogo está sendo preparado. Os livros aparecerão aqui assim que o acervo for cadastrado.'],
        'indisponivel' => ['cloud_off', 'O catálogo está temporariamente indisponível. Tente novamente em alguns minutos.'],
    ];
    $msg = $mensagens[catalogo()->fonte()] ?? null;
    if (!$msg) {
        return '';
    }
    return '<div class="info-strip" role="note"><span class="material-symbols-outlined" aria-hidden="true">' . e($msg[0]) . '</span> ' . e($msg[1]) . '</div>';
}
