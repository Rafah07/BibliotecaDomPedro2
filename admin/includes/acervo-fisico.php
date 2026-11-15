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
use Biblioteca\Repositories\OrganizacaoFisicaRepository;
use Biblioteca\Repositories\PendenciasRepository;
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\ConferenciaCadastroService;
use Biblioteca\Services\EtiquetaService;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\LocalizacaoService;
use Biblioteca\Services\ObraVerificacaoService;
use Biblioteca\Services\OrganizacaoFisicaService;
use Biblioteca\Services\VinculoObraService;

const FISICO_CSS = ['assets/css/acervo.css?v=20261114', 'assets/css/emprestimos.css?v=20261113', 'assets/css/inventario.css?v=20261114f', 'assets/css/organizacao.css?v=20261114b'];
const FISICO_JS = ['assets/js/inventario.js?v=20261114g'];

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

function conferencia_cadastro(): ConferenciaCadastroService
{
    static $s = null;
    return $s ??= new ConferenciaCadastroService(db(), inventario_servico());
}

function codigo_bdp_servico(): CodigoBdpService
{
    static $s = null;
    return $s ??= new CodigoBdpService(db());
}

function obra_verificacao_servico(): ObraVerificacaoService
{
    static $s = null;
    return $s ??= new ObraVerificacaoService(db());
}

function vinculo_obra_servico(): VinculoObraService
{
    static $s = null;
    return $s ??= new VinculoObraService(db());
}

/** Selo do status dos dados da obra: "Dados verificados" ou "Dados não verificados". */
function obra_badge_verificacao(?string $verificadoEm): string
{
    $ok = $verificadoEm !== null && $verificadoEm !== '';
    return '<span class="adm-status adm-tone--' . ($ok ? 'green' : 'amber') . ' adm-status--obra">' . icon($ok ? 'circle-check' : 'circle-help') . ' '
        . e(ObraVerificacaoService::rotulo($verificadoEm)) . '</span>';
}

/**
 * Situação de um exemplar físico NESTE inventário (status do exemplar, separado do status da obra).
 * @return array{0: string, 1: string} rótulo e tom
 */
function inventario_exemplar_status(array $ex): array
{
    if (($ex['situacao'] ?? '') === 'baixado') {
        return ['Baixado', 'muted'];
    }
    if (($ex['conferido_em'] ?? null) !== null) {
        return ['Encontrado', 'green'];
    }
    if (($ex['nao_localizado_em'] ?? null) !== null || ($ex['decisao'] ?? null) === 'registrar_extravio') {
        return ['Não localizado', 'red'];
    }
    if ($ex['emprestimo_aberto'] ?? null) {
        return ['Pendente · emprestado', 'indigo'];
    }
    return ['Pendente', 'amber'];
}

function organizacao_repo(): OrganizacaoFisicaRepository
{
    static $r = null;
    return $r ??= new OrganizacaoFisicaRepository(db());
}

function organizacao_servico(): OrganizacaoFisicaService
{
    static $s = null;
    return $s ??= new OrganizacaoFisicaService(db(), exemplares_servico());
}

function pendencias_repo(): PendenciasRepository
{
    static $r = null;
    return $r ??= new PendenciasRepository(db());
}

/** "Estante A" ou "Sala 1 · Estante A" (+ categoria). */
function fisico_rotulo_estante(array $e, bool $comCategoria = true): string
{
    $nome = (string)($e['estante_nome'] ?? $e['nome'] ?? ('Estante ' . ($e['estante_identificacao'] ?? $e['identificacao'] ?? '')));
    $setor = (string)($e['setor_nome'] ?? '');
    $cat = (string)($e['estante_categoria'] ?? $e['categoria'] ?? '');
    return ($setor !== '' ? $setor . ' · ' : '') . $nome . ($comCategoria && $cat !== '' ? ' — ' . $cat : '');
}

/** Texto curto de uma localização pelo id: "Estante A · Prateleira 1" (ou a localização anterior). */
function fisico_local_id(?int $id): string
{
    $p = $id ? organizacao_repo()->prateleira($id) : null;
    if (!$p) {
        return 'Sem localização';
    }
    return $p['estante_id'] !== null ? fisico_rotulo_estante($p, false) . ' · ' . fisico_rotulo_prateleira($p) : fisico_local($p) . ' (localização anterior)';
}

/** "Prateleira 3" (com a descrição, se houver). */
function fisico_rotulo_prateleira(array $p): string
{
    return 'Prateleira ' . $p['prateleira'] . (!empty($p['descricao']) ? ' (' . $p['descricao'] . ')' : '');
}

/**
 * Campo de localização com seletores DEPENDENTES: Setor (se houver) → Estante → Prateleira.
 * Envia só o campo $nome (id da prateleira, em localizacoes). Sem JavaScript, o seletor de
 * prateleiras funciona sozinho (agrupado por estante). Só aparecem prateleiras ativas de estantes
 * ativas; a localização atual ($selecionada) aparece mesmo se for antiga ou desativada.
 * Localizações antigas das planilhas nunca aparecem aqui.
 */
function fisico_campo_local(string $nome, ?int $selecionada, string $vazio = 'Não informar agora', string $idBase = 'local', ?array $opcoes = null): string
{
    $opcoes ??= organizacao_repo()->opcoesLocal($selecionada);
    $setores = [];
    $estantes = [];
    $grupos = [];
    $estanteSel = '';
    foreach ($opcoes as $o) {
        $chave = $o['estante_id'] !== null ? 'e' . (int)$o['estante_id'] : 'avulsa';
        if ($o['estante_id'] !== null) {
            $sid = $o['setor_id'] !== null ? (string)(int)$o['setor_id'] : '';
            if ($sid !== '') {
                $setores[$sid] = $o['setor_nome'];
            }
            $estantes[$chave] ??= ['setor' => $sid, 'rotulo' => fisico_rotulo_estante($o)];
            $rotulo = fisico_rotulo_prateleira($o) . (!(int)$o['ativa'] || !(int)$o['estante_ativa'] ? ' — desativada' : '');
        } else {
            $rotulo = fisico_local($o) . ' (localização anterior, sem estante cadastrada)';
        }
        $grupos[$chave][] = ['id' => (int)$o['id'], 'rotulo' => $rotulo];
        if ((int)$o['id'] === $selecionada) {
            $estanteSel = $chave;
        }
    }
    $id = e($idBase);
    $html = '<div class="adm-local-dep" data-local-dep>';
    if ($setores) {
        $html .= '<label class="adm-field adm-local-dep__aux" hidden><span class="adm-field__sublabel">Setor</span><select class="adm-select" data-local-setor aria-label="Setor"><option value="">Todos os setores</option>';
        $setorSel = $estanteSel !== '' && isset($estantes[$estanteSel]) ? $estantes[$estanteSel]['setor'] : '';
        foreach ($setores as $sid => $sn) {
            $html .= '<option value="' . e($sid) . '"' . ($sid === $setorSel ? ' selected' : '') . '>' . e($sn) . '</option>';
        }
        $html .= '</select></label>';
    }
    $html .= '<label class="adm-field adm-local-dep__aux" hidden><span class="adm-field__sublabel">Estante</span><select class="adm-select" data-local-estante aria-label="Estante"><option value="">Escolha a estante</option>';
    foreach ($estantes as $chave => $e) {
        $html .= '<option value="' . e($chave) . '" data-setor="' . e($e['setor']) . '"' . ($chave === $estanteSel ? ' selected' : '') . '>' . e($e['rotulo']) . '</option>';
    }
    if (isset($grupos['avulsa'])) {
        $html .= '<option value="avulsa"' . ($estanteSel === 'avulsa' ? ' selected' : '') . '>Localização anterior (sem estante)</option>';
    }
    $html .= '</select></label>';
    $html .= '<label class="adm-field" for="' . $id . '"><span class="adm-field__sublabel">Prateleira</span><select class="adm-select" name="' . e($nome) . '" id="' . $id . '" data-local-prateleira>';
    $html .= '<option value="">' . e($vazio) . '</option>';
    foreach ($grupos as $chave => $itens) {
        $label = $chave === 'avulsa' ? 'Localização anterior (sem estante)' : $estantes[$chave]['rotulo'];
        $html .= '<optgroup label="' . e($label) . '" data-estante="' . e($chave) . '">';
        foreach ($itens as $i) {
            $html .= '<option value="' . $i['id'] . '"' . ($i['id'] === $selecionada ? ' selected' : '') . '>' . e($i['rotulo']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    $html .= '</select></label>';
    if (!$estantes && !isset($grupos['avulsa'])) {
        $html .= '<p class="adm-field__hint">Nenhuma estante cadastrada ainda. ' . (auth_pode('localizacoes.gerenciar') ? '<a class="adm-link" href="' . e(admin_url('organizacao-fisica')) . '">Cadastre a estrutura em Organização Física</a>.' : 'Peça a quem gerencia as localizações para cadastrar as estantes em Organização Física.') . '</p>';
    }
    return $html . '</div>';
}

function etiquetas_servico(): EtiquetaService
{
    static $s = null;
    return $s ??= new EtiquetaService(db());
}

/** Nome impresso nas etiquetas (Sistema → Configurações ou ETIQUETA_BIBLIOTECA no .env). */
function etiquetas_biblioteca(): string
{
    $nome = trim((string)config_valor('ETIQUETA_BIBLIOTECA', ''));
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
    $grupo = null;
    foreach ($locais as $l) {
        // Prateleiras agrupadas pela estante (Organização Física); localizações avulsas pelo setor.
        $estruturada = ($l['estante_id'] ?? null) !== null;
        $chave = $estruturada ? 'e' . $l['estante_id'] : 's' . $l['setor'];
        if ($chave !== $grupo) {
            $rotuloGrupo = $estruturada ? fisico_rotulo_estante($l) : ($l['setor'] !== '' ? $l['setor'] : 'Sem setor') . ' (localizações anteriores)';
            $html .= ($grupo !== null ? '</optgroup>' : '') . '<optgroup label="' . e($rotuloGrupo) . '">';
            $grupo = $chave;
        }
        $rotulo = ($estruturada ? fisico_rotulo_prateleira($l) : fisico_local($l)) . ($l['codigo'] ? ' (' . $l['codigo'] . ')' : '') . (!(int)$l['ativa'] ? ' — desativada' : '');
        $html .= '<option value="' . (int)$l['id'] . '"' . ((int)$l['id'] === $selecionada ? ' selected' : '') . '>' . e($rotulo) . '</option>';
    }
    return $html . ($grupo !== null ? '</optgroup>' : '') . '</select>';
}

/**
 * Estado de conservação. Começa em "Não avaliado": a equipe escolhe a classificação depois de olhar
 * o livro (nada vem marcado como "Bom" sem avaliação). As opções antigas (Danificado, Necessita
 * reparo) só aparecem quando já estão gravadas no exemplar.
 */
function fisico_select_conservacao(string $nome, string $selecionado = 'nao_avaliado', string $id = ''): string
{
    $html = '<select class="adm-select" name="' . e($nome) . '"' . ($id ? ' id="' . e($id) . '"' : '') . ' required>';
    foreach (ACERVO_CONSERVACAO as $valor => $rotulo) {
        if (!in_array($valor, ExemplarService::CONSERVACAO_OPCOES, true) && $valor !== $selecionado) {
            continue;
        }
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
        if ($i['emprestimo_aberto'] ?? null) {
            return ['Emprestado (fora da estante)', 'indigo'];
        }
        if ($i['decisao'] === 'registrar_extravio') {
            return ['Não localizado · extravio registrado', 'red'];
        }
        if (($i['nao_localizado_em'] ?? null) !== null) {
            return ['Não localizado', 'red'];
        }
        return $i['decisao'] ? ['Não localizado · mantido', 'muted'] : ['Pendente', 'amber'];
    }
    if (inventario_divergencias($i)) {
        return $i['decisao'] ? ['Divergência decidida', 'blue'] : ['Divergência pendente', 'red'];
    }
    return [(int)$i['cadastrado_no_inventario'] ? 'Encontrado · cadastrado na conferência' : 'Encontrado', 'green'];
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
