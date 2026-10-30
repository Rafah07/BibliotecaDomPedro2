<?php
/**
 * Ponto de acesso do site público aos dados: catálogo (somente leitura) e textos institucionais.
 * Sem banco configurado ou com o banco fora do ar, as páginas mostram estados vazios com aviso:
 * o site público nunca exibe livros fictícios.
 */
require_once __DIR__ . '/../config/database.php';

use Biblioteca\Repositories\CatalogoRepository;
use Biblioteca\Repositories\SiteConfiguracaoRepository;
use Biblioteca\Services\CatalogoService;
use Biblioteca\Services\SiteConteudo;
use Biblioteca\Support\RegrasCirculacao;

/** Conexão para o site público, ou null (não configurado ou indisponível). */
function site_pdo(): ?PDO
{
    static $pdo = false;
    if ($pdo === false) {
        try {
            $pdo = db();
        } catch (DbIndisponivel $e) {
            $pdo = null;
        }
    }
    return $pdo;
}

function catalogo(): CatalogoService
{
    static $servico = null;
    if ($servico === null) {
        $pdo = site_pdo();
        $servico = new CatalogoService($pdo ? new CatalogoRepository($pdo) : null, db_configurado() && !$pdo);
    }
    return $servico;
}

function site_conteudo(): SiteConteudo
{
    static $conteudo = null;
    if ($conteudo === null) {
        $pdo = site_pdo();
        $conteudo = new SiteConteudo($pdo ? new SiteConfiguracaoRepository($pdo) : null, RegrasCirculacao::doAmbiente('env'));
    }
    return $conteudo;
}

/** Aviso exibido no topo das páginas do catálogo quando não há livros para mostrar ('' com acervo). */
function catalogo_aviso(): string
{
    $mensagens = [
        'vazio' => ['inbox', 'O catálogo está sendo preparado. Os livros aparecerão aqui assim que o acervo for cadastrado.'],
        'indisponivel' => ['circle-alert', 'O catálogo está temporariamente indisponível. Tente novamente em alguns minutos.'],
        'nao_configurado' => ['circle-alert', 'O catálogo ainda não está disponível neste endereço. Procure a equipe da biblioteca.'],
    ];
    $msg = $mensagens[catalogo()->fonte()] ?? null;
    if (!$msg) {
        return '';
    }
    return '<div class="info-strip" role="status">' . icon($msg[0]) . ' <span>' . e($msg[1]) . '</span></div>';
}

/**
 * Converte um texto institucional em HTML seguro: tudo é escapado; linhas em branco separam
 * parágrafos e linhas iniciadas por "- " ou "* " viram itens de lista.
 */
function site_texto_html(string $texto): string
{
    $html = '';
    foreach (preg_split('/\n\s*\n/', trim(str_replace("\r\n", "\n", $texto))) ?: [] as $bloco) {
        $linhas = array_values(array_filter(array_map('trim', explode("\n", $bloco)), static fn ($l) => $l !== ''));
        if (!$linhas) {
            continue;
        }
        $lista = array_filter($linhas, static fn ($l) => preg_match('/^[-*•]\s+/u', $l));
        if (count($lista) === count($linhas)) {
            $html .= '<ul>' . implode('', array_map(static fn ($l) => '<li>' . e(preg_replace('/^[-*•]\s+/u', '', $l)) . '</li>', $linhas)) . '</ul>';
        } else {
            $html .= '<p>' . implode('<br>', array_map('e', $linhas)) . '</p>';
        }
    }
    return $html;
}

/** Bloco de texto institucional ou, se vazio, a indicação de que aguarda a escola. */
function site_bloco(string $chave): string
{
    $conteudo = site_conteudo();
    if (!$conteudo->preenchido($chave)) {
        return '<p class="pending-note">' . icon('clock') . ' ' . e(SiteConteudo::MENSAGEM_PENDENTE) . '</p>';
    }
    return '<div class="rich-text">' . site_texto_html($conteudo->valor($chave)) . '</div>';
}
