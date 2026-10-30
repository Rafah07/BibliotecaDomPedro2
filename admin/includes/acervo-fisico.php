<?php
/**
 * Acervo físico no painel: exemplares, localizações, inventário digital e etiquetas.
 *
 * - Funciona só com o banco configurado (os exemplares e inventários reais ficam no MySQL).
 *   No modo demonstrativo, as páginas de exemplares mostram a versão fictícia antiga.
 * - As permissões (exemplares.*, localizacoes.gerenciar, inventario.*, etiquetas.*) são
 *   conferidas no servidor em cada página e em cada ação enviada.
 * - Gravações: Biblioteca\Services\ExemplarService, LocalizacaoService, InventarioService e
 *   EtiquetaService (transações, bloqueio das linhas e registro das ações).
 * - Nenhuma destas páginas mostra dados de alunos.
 */
require_once __DIR__ . '/componentes.php';
require_once __DIR__ . '/acervo.php'; // acervo_breadcrumb, ACERVO_SITUACOES, ACERVO_CONSERVACAO

use Biblioteca\Repositories\ExemplarRepository;
use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\EtiquetaService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LocalizacaoService;

const FISICO_CSS = ['assets/css/acervo.css?v=20261030', 'assets/css/emprestimos.css?v=20261030', 'assets/css/inventario.css?v=20261030'];
const FISICO_JS = ['assets/js/inventario.js?v=20261030'];

const INVENTARIO_TONS = ['aberto' => 'blue', 'em_andamento' => 'green', 'pausado' => 'amber', 'concluido' => 'indigo', 'cancelado' => 'muted'];

function exemplares_repo(): ExemplarRepository
{
    static $r = null;
    return $r ??= new ExemplarRepository(db());
}

function exemplares_servico(): ExemplarService
{
    static $s = null;
    return $s ??= new ExemplarService(db());
}

function localizacoes_servico(): LocalizacaoService
{
    static $s = null;
    return $s ??= new LocalizacaoService(db());
}

function inventario_repo(): InventarioRepository
{
    static $r = null;
    return $r ??= new InventarioRepository(db());
}

function inventario_servico(): InventarioService
{
    static $s = null;
    return $s ??= new InventarioService(db(), exemplares_servico(), inventario_repo());
}

function etiquetas_servico(): EtiquetaService
{
    static $s = null;
    return $s ??= new EtiquetaService(db());
}

/** Nome impresso nas etiquetas (ETIQUETA_BIBLIOTECA no .env). */
function etiquetas_biblioteca(): string
{
    $nome = trim((string)env('ETIQUETA_BIBLIOTECA', ''));
    return $nome !== '' ? mb_substr($nome, 0, 60) : 'Biblioteca Dom Pedro II';
}

/** Início das páginas: permissão conferida no servidor e banco obrigatório. */
function fisico_iniciar(string $permissao = 'painel.ver'): array
{
    $usuario = auth_exigir($permissao);
    if (db_modo() !== 'mysql') {
        admin_pagina_erro('Disponível só com o banco de dados', 'Exemplares, localizações, inventário e etiquetas usam os dados reais do MySQL. Configure o banco para usar estas páginas.', 'clipboard-check');
        exit;
    }
    try {
        db();
    } catch (DbIndisponivel $e) {
        http_response_code(503);
        admin_pagina_erro('Banco de dados indisponível', $e->getMessage());
        exit;
    }
    return $usuario;
}

/** Recusa uma ação sem a permissão (403), mesmo que o formulário tenha sido enviado à força. */
function fisico_exigir(string $permissao): void
{
    if (!auth_pode($permissao)) {
        http_response_code(403);
        admin_pagina_erro('Acesso não permitido', 'Seu perfil não tem permissão para esta ação. Fale com a bibliotecária responsável.', 'lock');
        exit;
    }
}

/** Só o tipo do erro vai para o log; a tela recebe uma mensagem genérica. */
function fisico_erro_tecnico(Throwable $e, string $contexto): string
{
    $codigo = $e instanceof PDOException ? ' ' . ($e->errorInfo[1] ?? $e->getCode()) : '';
    error_log('[biblioteca] Erro em ' . $contexto . ': ' . get_class($e) . $codigo . ' em ' . basename($e->getFile()) . ':' . $e->getLine());
    if ($e instanceof PDOException && (int)($e->errorInfo[1] ?? 0) === 1644) {
        // Regra do banco (gatilho): a mensagem foi escrita para a tela.
        return (string)($e->errorInfo[2] ?? 'Operação recusada pelo banco de dados.');
    }
    return 'Não foi possível concluir a operação. Nada foi gravado. Tente novamente; se o problema continuar, verifique se as migrações foram aplicadas (php database/migrar.php).';
}

function fisico_numero(int|float $n): string
{
    return number_format($n, 0, ',', '.');
}

/** Texto da localização a partir das colunas setor/estante/prateleira/complemento (com prefixo opcional). */
function fisico_local(array $linha, string $prefixo = ''): string
{
    $l = ['setor' => $linha[$prefixo . 'setor'] ?? null, 'estante' => $linha[$prefixo . 'estante'] ?? '', 'prateleira' => $linha[$prefixo . 'prateleira'] ?? '', 'complemento' => $linha[$prefixo . 'complemento'] ?? ''];
    return ExemplarService::localizacaoTexto($l['setor'] === null ? null : $l);
}

function fisico_badge_inventario(string $status): string
{
    return '<span class="adm-status adm-tone--' . (INVENTARIO_TONS[$status] ?? 'blue') . '">' . e(InventarioService::STATUS[$status] ?? $status) . '</span>';
}

/** Seletor de localizações ativas. $vazio: rótulo da opção sem localização (null = obrigatório). */
function fisico_select_local(string $nome, ?int $selecionada, ?string $vazio = 'Sem localização definida', string $id = '', array $locais = []): string
{
    $locais = $locais ?: localizacoes_servico()->ativas($selecionada);
    $html = '<select class="adm-select" name="' . e($nome) . '"' . ($id ? ' id="' . e($id) . '"' : '') . ($vazio === null ? ' required' : '') . '>';
    $html .= '<option value="">' . e($vazio ?? 'Selecione a localização') . '</option>';
    $setor = null;
    foreach ($locais as $l) {
        if ($l['setor'] !== $setor) {
            $html .= ($setor !== null ? '</optgroup>' : '') . '<optgroup label="' . e($l['setor']) . '">';
            $setor = $l['setor'];
        }
        $rotulo = fisico_local($l) . ($l['codigo'] ? ' (' . $l['codigo'] . ')' : '') . (!(int)$l['ativa'] ? ' — desativada' : '');
        $html .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $selecionada ? ' selected' : '') . '>' . e($rotulo) . '</option>';
    }
    return $html . ($setor !== null ? '</optgroup>' : '') . '</select>';
}

function fisico_select_conservacao(string $nome, string $selecionado = 'bom', string $id = ''): string
{
    $html = '<select class="adm-select" name="' . e($nome) . '"' . ($id ? ' id="' . e($id) . '"' : '') . ' required>';
    foreach (ACERVO_CONSERVACAO as $valor => $rotulo) {
        $html .= '<option value="' . $valor . '"' . ($valor === $selecionado ? ' selected' : '') . '>' . e($rotulo) . '</option>';
    }
    return $html . '</select>';
}

/** Situações que podem ser escolhidas manualmente (empréstimo, extravio e baixa têm fluxo próprio). */
function fisico_select_situacao(string $nome, string $selecionada = 'disponivel', string $id = ''): string
{
    $dicas = [
        'disponivel' => 'Disponível para empréstimo',
        'aguardando' => 'Aguardando liberação (ainda não empresta)',
        'manutencao' => 'Em manutenção',
        'danificado' => 'Danificado (fora de circulação)',
    ];
    $html = '<select class="adm-select" name="' . e($nome) . '"' . ($id ? ' id="' . e($id) . '"' : '') . ' required>';
    foreach (ExemplarService::SITUACOES_MANUAIS as $valor) {
        $html .= '<option value="' . $valor . '"' . ($valor === $selecionada ? ' selected' : '') . '>' . e($dicas[$valor]) . '</option>';
    }
    return $html . '</select>';
}

/** Cartão de número (mesmo visual do dashboard e dos empréstimos). */
function fisico_stat(string $titulo, int $valor, string $descricao, string $icone, string $tom, ?string $href = null): string
{
    $tag = $href ? 'a' : 'article';
    return '<' . $tag . ' class="adm-card adm-stat adm-stat--compact' . ($href ? ' adm-stat--action' : '') . '"' . ($href ? ' href="' . e($href) . '"' : '') . '>'
        . '<div class="adm-stat__top"><p class="adm-stat__title">' . e($titulo) . '</p><span class="adm-icon-box adm-icon-box--sm adm-tone--' . $tom . '">' . icon($icone) . '</span></div>'
        . '<p class="adm-stat__value">' . fisico_numero($valor) . '</p><p class="adm-stat__desc">' . e($descricao) . '</p></' . $tag . '>';
}

/** Rótulo de cada categoria de divergência de um item do inventário. */
function inventario_divergencias(array $i): array
{
    $lista = [];
    if ((int)$i['divergencia_local']) {
        $lista[] = 'Localização: cadastrado em ' . fisico_local($i, 'atual_') . ', encontrado em ' . fisico_local($i, 'encontrada_');
    }
    if ((int)$i['divergencia_estado']) {
        $lista[] = 'Estado: cadastrado como ' . (ACERVO_CONSERVACAO[$i['estado_conservacao']] ?? $i['estado_conservacao']) . ', encontrado ' . mb_strtolower(ACERVO_CONSERVACAO[$i['estado_encontrado']] ?? (string)$i['estado_encontrado']);
    }
    if ((int)$i['divergencia_situacao']) {
        $lista[] = 'Situação: ' . ($i['emprestimo_aberto'] ? 'há empréstimo ativo, mas o livro estava na biblioteca' : 'registrado como ' . mb_strtolower(ExemplarService::rotuloSituacao($i['situacao'])));
    }
    return $lista;
}

/** Situação do item na conferência: [rótulo, tom]. */
function inventario_item_situacao(array $i): array
{
    if ($i['conferido_em'] === null) {
        if ($i['emprestimo_aberto']) {
            return ['Emprestado (fora da estante)', 'indigo'];
        }
        return $i['decisao'] === 'registrar_extravio' ? ['Registrado como extraviado', 'red'] : ($i['decisao'] ? ['Não localizado · mantido', 'muted'] : ['Não conferido', 'amber']);
    }
    if (inventario_divergencias($i)) {
        return $i['decisao'] ? ['Divergência decidida', 'blue'] : ['Divergência pendente', 'red'];
    }
    return [(int)$i['cadastrado_no_inventario'] ? 'Cadastrado e conferido' : 'Conferido', 'green'];
}

/** Valores do formulário de cadastro de exemplar (form-exemplar.php). */
function fisico_dados_exemplar(array $post): array
{
    $fx = [];
    foreach (['quantidade', 'estado_conservacao', 'situacao', 'localizacao_id', 'nova_setor', 'nova_estante', 'nova_prateleira', 'nova_complemento', 'observacoes'] as $campo) {
        $fx[$campo] = mb_substr(admin_param($post, $campo), 0, 500);
    }
    return $fx;
}

/**
 * Localização escolhida no formulário. Se o setor de uma nova localização foi preenchido
 * (e a pessoa pode gerenciar localizações), usa a existente igual ou cadastra uma nova.
 */
function fisico_resolver_local(array $fx, int $usuarioId): ?int
{
    if ($fx['nova_setor'] !== '' && auth_pode('localizacoes.gerenciar')) {
        return localizacoes_servico()->encontrarOuCriar(['setor' => $fx['nova_setor'], 'estante' => $fx['nova_estante'], 'prateleira' => $fx['nova_prateleira'], 'complemento' => $fx['nova_complemento']], $usuarioId);
    }
    return (int)$fx['localizacao_id'] ?: null;
}
