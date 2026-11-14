<?php
/**
 * Conferência do inventário em andamento.
 *
 * OBRA × EXEMPLAR (migração 120):
 * - A OBRA (livros) tem os dados bibliográficos e o status "Dados não verificados" ou
 *   "Dados verificados". "Verificar e corrigir dados" abre um painel aqui mesmo; só o botão
 *   "Salvar e marcar dados como verificados" muda o status. Nenhum campo é obrigatório.
 * - Cada EXEMPLAR físico tem o seu código BDP e a sua situação no inventário (Pendente,
 *   Encontrado, Não localizado). "+ Registrar exemplar" (só para obras verificadas) abre o
 *   painel do exemplar, com "Gerar código BDP" (reserva feita no servidor, CodigoBdpService).
 *   A mesma obra pode ter vários exemplares sem repetir o cadastro bibliográfico.
 * - Registros importados da planilha sem exemplar continuam pesquisáveis e verificáveis, mas
 *   não contam como exemplares encontrados. Ao registrar um exemplar, a equipe escolhe a linha
 *   da planilha que ele representa (ou "exemplar a mais"). Nada é unido nem criado sozinho.
 *
 * Página: &obra=ID abre a ficha da obra (dados + exemplares + linhas da planilha).
 * - A identificação de exemplar já etiquetado é pela DIGITAÇÃO do código BDP (ex.: BDP-000001),
 *   seguida de Enter (busca exata). Texto que não é código pesquisa por título, autor ou ISBN.
 * - Código conferido de novo: aviso com os dados anteriores; nada é gravado de novo.
 *   A correção é uma ação separada (inventario.gerenciar), com motivo.
 * - "Modo rápido" confere ao digitar o código, com o estado já cadastrado e a localização em conferência.
 * - "+ Cadastrar livro não listado" cadastra a obra, verifica os dados e registra o primeiro
 *   exemplar no mesmo passo, depois de mostrar obras parecidas. Token de uso único contra clique duplo.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\ObraVerificacaoService;
use Biblioteca\Services\ConferenciaDuplicadaException;
use Biblioteca\Support\CodigoBdp;

$usuario = fisico_iniciar('inventario.conferir');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$base = admin_url('inventario-conferencia') . '?id=' . $id;
$podeGerenciar = auth_pode('inventario.gerenciar');
$podeCadastrar = auth_pode('exemplares.gerenciar');
$podeEditarLivro = auth_pode('livros.editar');
$sessao = $_SESSION['inventario_conferencia'][$id] ?? ['local' => null, 'rapido' => false];
$erro = null;
$novoForm = null;          // valores do formulário "livro não listado" após um erro ou na etapa de correspondências
$correspondencias = null;  // livros parecidos encontrados antes de cadastrar
$registrarForm = null;     // valores do painel "+ Registrar exemplar" após um erro ou "Gerar código BDP" sem JavaScript
$verificarForm = null;     // valores do painel "Verificar e corrigir dados" após um erro
$painel = admin_param($_GET, 'painel'); // painel aberto ao carregar: verificar | registrar

/** Token de uso único do formulário "livro não listado" (protege contra clique duplo e reenvio). */
function conferencia_token_novo(int $inventarioId): string
{
    $t = bin2hex(random_bytes(16));
    $_SESSION['inv_nao_listado'][$t] = $inventarioId;
    $_SESSION['inv_nao_listado'] = array_slice($_SESSION['inv_nao_listado'], -20, null, true);
    return $t;
}

/** Dados do exemplar físico enviados pelo formulário (código BDP, estado, localização, observação, situação). */
function conferencia_fisico(array $post, ?int $localPadrao): array
{
    return [
        'codigo' => mb_substr(admin_param($post, 'codigo_bdp'), 0, 30),
        'estado_conservacao' => admin_param($post, 'estado_conservacao', 'bom'),
        'localizacao_id' => admin_param($post, 'localizacao_id') !== '' ? admin_param($post, 'localizacao_id') : $localPadrao,
        'observacao' => mb_substr(admin_param($post, 'observacao'), 0, 500),
        'situacao_inventario' => admin_param($post, 'situacao_inventario', 'encontrado'),
        'item_id' => admin_param($post, 'item_id'),
    ];
}

/** Mensagem curta do resultado de uma conferência. */
function conferencia_mensagem(array $item): array
{
    $divs = inventario_divergencias($item);
    if (!$divs) {
        return ['sucesso', $item['codigo_individual'] . ' conferido.', $item['titulo']];
    }
    return ['aviso', $item['codigo_individual'] . ' conferido com divergência.', implode(' · ', $divs) . ($item['decisao'] ? ' · Cadastro atualizado.' : ' · A decisão fica pendente no inventário.')];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    try {
        switch ($acao) {
            case 'local':
                $local = (int)admin_param($_POST, 'local_conferencia') ?: null;
                $_SESSION['inventario_conferencia'][$id] = ['local' => $local, 'rapido' => admin_param($_POST, 'rapido') === '1'];
                admin_flash('sucesso', 'Preferências da conferência salvas.', $local ? 'A localização escolhida vem preenchida em cada conferência.' : 'Nenhuma localização em conferência.');
                admin_redirecionar($base);
            case 'ler':
                $texto = trim(mb_substr(admin_param($_POST, 'leitura'), 0, 100));
                if ($texto === '') {
                    admin_redirecionar($base);
                }
                if (!EmprestimoRepository::codigoExemplar($texto)) {
                    if (CodigoBdp::pareceCodigo($texto)) {
                        throw new AcervoException(CodigoBdp::mensagemInvalido($texto));
                    }
                    admin_redirecionar($base . '&q=' . rawurlencode($texto));
                }
                $ex = exemplares_repo()->porCodigo($texto);
                if ($ex && $sessao['rapido']) {
                    $item = inventario_servico()->conferir($id, (int)$ex['id'], ['estado_conservacao' => $ex['estado_conservacao'], 'localizacao_id' => $sessao['local']], $usuario['id'], false);
                    admin_flash(...conferencia_mensagem($item));
                    admin_redirecionar($base);
                }
                admin_redirecionar($base . '&codigo=' . rawurlencode(EmprestimoRepository::codigoExemplar($texto)));
            case 'conferir':
                // Exemplar já etiquetado em mãos: "Marcar como encontrado" (status do EXEMPLAR).
                $item = inventario_servico()->conferir($id, (int)admin_param($_POST, 'exemplar_id'), [
                    'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                    'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                    'observacao' => admin_param($_POST, 'observacao'),
                    'aplicar' => admin_param($_POST, 'aplicar') === '1',
                ], $usuario['id'], $podeGerenciar);
                admin_flash(...conferencia_mensagem($item));
                admin_redirecionar($base);
            case 'verificar_obra':
                // "Verificar e corrigir dados": dados da OBRA. Só "Salvar e marcar dados como verificados" muda o status.
                fisico_exigir('livros.editar');
                $livroAlvo = (int)admin_param($_POST, 'livro_id');
                $marcar = admin_param($_POST, 'marcar_verificado') === '1';
                $verificarForm = $_POST;
                $cad = conferencia_cadastro();
                $r = $cad->atualizarLivro($livroAlvo, $cad->dados($_POST), $usuario['id'], admin_param($_POST, 'confirmar_alertas') === '1', $marcar, $id);
                $alertasTxt = $r['alertas_abertos'] ? $r['alertas_abertos'] . ' alerta(s) cadastral(is) continuam abertos (não impedem a verificação).' : 'Nenhum alerta cadastral aberto.';
                if ($marcar) {
                    admin_flash('sucesso', 'Dados verificados.', ($r['alteracoes'] ? 'Correções salvas. ' : '') . 'Agora registre os exemplares desta obra com “+ Registrar exemplar”. ' . $alertasTxt);
                } elseif ($r['alteracoes']) {
                    admin_flash($r['verificacao'] === 'verificado' ? 'sucesso' : 'aviso', 'Alterações salvas.', $r['verificacao'] === 'verificado'
                        ? 'A obra continua com os dados verificados. ' . $alertasTxt
                        : 'A obra está com “Dados não verificados”. Quando terminar de conferir, use “Salvar e marcar dados como verificados”. ' . $alertasTxt);
                } else {
                    admin_flash('aviso', 'Nenhuma informação alterada.', $alertasTxt);
                }
                admin_redirecionar($base . '&obra=' . $livroAlvo . '#obra');
            case 'gerar_codigo':
                // "Gerar código BDP" sem JavaScript: reserva no servidor e volta ao formulário com o código.
                fisico_exigir('exemplares.gerenciar');
                $r = codigo_bdp_servico()->reservar($usuario['id'], (int)admin_param($_POST, 'livro_id') ?: null);
                $valores = array_merge($_POST, ['codigo_bdp' => $r['codigo'], 'codigo_gerado' => '1']);
                if (admin_param($_POST, 'origem_form') === 'nao_listado') {
                    $novoForm = $valores;
                } else {
                    $registrarForm = $valores;
                    $painel = 'registrar';
                }
                break;
            case 'registrar_exemplar':
                // "+ Registrar exemplar": exemplar físico de uma obra verificada, com código BDP definitivo ao salvar.
                fisico_exigir('exemplares.gerenciar');
                $livroAlvo = (int)admin_param($_POST, 'livro_id');
                $registrarForm = $_POST;
                $painel = 'registrar';
                $f = conferencia_fisico($_POST, $sessao['local']);
                $c = inventario_servico()->registrarExemplar($id, $livroAlvo, [
                    'codigo' => $f['codigo'], 'estado_conservacao' => $f['estado_conservacao'], 'localizacao_id' => $f['localizacao_id'],
                    'observacoes' => $f['observacao'], 'situacao_inventario' => $f['situacao_inventario'], 'item_id' => $f['item_id'],
                ], $usuario['id']);
                admin_flash('sucesso', 'Exemplar ' . $c['codigo'] . ' registrado: ' . Biblioteca\Services\InventarioService::SITUACOES_REGISTRO[$c['situacao']] . '.',
                    'Confira se o carimbo do livro mostra exatamente este código. Encontrou outra cópia da mesma obra? Use “+ Registrar exemplar” de novo, sem verificar os dados outra vez.');
                admin_redirecionar($base . '&obra=' . $livroAlvo . '&criados=' . (int)$c['id'] . '#exemplares');
            case 'nao_localizado':
                $n = inventario_servico()->marcarNaoLocalizados($id, [(int)admin_param($_POST, 'item_id')], admin_param($_POST, 'observacao'), $usuario['id']);
                admin_flash($n ? 'sucesso' : 'aviso', $n ? 'Marcado como não localizado.' : 'Nada foi alterado.', $n ? 'Se o livro aparecer depois, registre o exemplar ligado a esta linha, ou use "Voltar para pendente".' : 'O item já tinha resultado ou está emprestado.');
                admin_redirecionar($base . '&obra=' . (int)admin_param($_POST, 'livro_id') . '#registros');
            case 'voltar_pendente':
                $itemId = (int)admin_param($_POST, 'item_id');
                $it = inventario_repo()->item($itemId);
                if ($it && $it['conferido_em'] !== null) {
                    fisico_exigir('inventario.gerenciar');
                }
                inventario_servico()->voltarPendente($itemId, $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'O item voltou para Pendente.', 'Ele aparece de novo entre os livros a conferir.');
                admin_redirecionar($base . ($it ? '&obra=' . (int)$it['livro_id'] . '#registros' : ''));
            case 'nao_listado':
                fisico_exigir('exemplares.gerenciar');
                $token = admin_param($_POST, 'token');
                $feito = $_SESSION['inv_nao_listado_feito'][$token] ?? null;
                if ($feito) {
                    admin_flash('aviso', 'Este livro já foi cadastrado' . ($feito['codigo'] ? ' (' . $feito['codigo'] . ')' : '') . '.', 'O formulário foi enviado de novo (clique duplo ou atualização da página). Nada foi gravado outra vez.');
                    admin_redirecionar($base . '&obra=' . (int)$feito['livro_id']);
                }
                if (!isset($_SESSION['inv_nao_listado'][$token])) {
                    throw new AcervoException('O formulário expirou. Abra "+ Cadastrar livro não listado" de novo e confira se o livro já não foi cadastrado.');
                }
                $novoForm = $_POST;
                $marcar = admin_param($_POST, 'marcar_verificado') === '1';
                if ($marcar) {
                    fisico_exigir('livros.editar');
                }
                $cad = conferencia_cadastro();
                $dadosNovo = $cad->dados($_POST);
                if (admin_param($_POST, 'escolha') !== 'novo') {
                    $correspondencias = $cad->correspondencias($dadosNovo);
                    if ($correspondencias) {
                        break; // mostra as obras parecidas antes de gravar
                    }
                }
                $res = $cad->cadastrarNaoListado($id, $dadosNovo, conferencia_fisico($_POST, $sessao['local']), $usuario['id'], null, $marcar);
                unset($_SESSION['inv_nao_listado'][$token]);
                $_SESSION['inv_nao_listado_feito'][$token] = ['codigo' => $res['codigo'], 'livro_id' => $res['livro_id']];
                $_SESSION['inv_nao_listado_feito'] = array_slice($_SESSION['inv_nao_listado_feito'], -20, null, true);
                $alertasTxt = $res['alertas'] ? ' ' . $res['alertas'] . ' alerta(s) cadastral(is) ficaram registrados para completar depois.' : '';
                if ($res['exemplar_id']) {
                    admin_flash('sucesso', 'Obra cadastrada com dados verificados e exemplar ' . $res['codigo'] . ' registrado.', 'Confira se o carimbo do livro mostra este código.' . $alertasTxt);
                    admin_redirecionar($base . '&obra=' . $res['livro_id'] . '&criados=' . (int)$res['exemplar_id'] . '#exemplares');
                }
                admin_flash('sucesso', 'Obra cadastrada com “Dados não verificados”.', 'Nenhum exemplar foi registrado. Quando conferir os dados, use “Verificar e corrigir dados” e depois “+ Registrar exemplar”.' . $alertasTxt);
                admin_redirecionar($base . '&obra=' . $res['livro_id'] . '#obra');
            case 'corrigir':
                fisico_exigir('inventario.gerenciar');
                $item = inventario_servico()->corrigir((int)admin_param($_POST, 'item_id'), [
                    'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                    'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                    'observacao' => admin_param($_POST, 'observacao'),
                ], $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Conferência de ' . $item['codigo_individual'] . ' corrigida.', 'A correção ficou registrada no histórico, com o motivo.');
                admin_redirecionar($base);
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (ConferenciaDuplicadaException $e) {
        admin_flash('aviso', 'Já estava marcado como encontrado: nada foi gravado de novo.', $e->getMessage());
        $cod = (string)($e->item['codigo_individual'] ?? '');
        admin_redirecionar($base . ($cod !== '' ? '&codigo=' . rawurlencode($cod) : ''));
    } catch (AcervoException $e) {
        $erro = $e->getMessage();
    } catch (PDOException $e) {
        $erro = fisico_erro_tecnico($e, 'conferência do inventário');
    }
}

try {
    $inv = inventario_repo()->buscar($id);
    if (!$inv) {
        http_response_code(404);
        admin_pagina_erro('Inventário não encontrado', 'Confira o endereço e tente novamente.', 'clipboard-check');
        exit;
    }
    // Endereços antigos (antes da separação obra × exemplar) continuam funcionando.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        if ($antigo = (int)admin_param($_GET, 'corrigir')) {
            admin_redirecionar($base . '&obra=' . $antigo . '&painel=verificar#obra');
        }
        if ($antigo = (int)admin_param($_GET, 'livro')) {
            admin_redirecionar($base . '&obra=' . $antigo . '&painel=registrar#exemplares');
        }
        if ($antigo = (int)admin_param($_GET, 'item')) {
            $it = inventario_repo()->item($antigo);
            if ($it && (int)$it['inventario_id'] === $id) {
                admin_redirecionar($it['exemplar_id'] !== null ? $base . '&codigo=' . rawurlencode((string)$it['codigo_individual']) : $base . '&obra=' . (int)$it['livro_id'] . '#registro-' . $antigo);
            }
        }
    }
    $codigoLido = mb_substr(admin_param($_GET, 'codigo'), 0, 30);
    $q = mb_substr(admin_param($_GET, 'q'), 0, 100);
    $exemplar = $codigoLido !== '' ? exemplares_repo()->porCodigo($codigoLido) : null;
    $itemAnterior = $exemplar ? inventario_repo()->itemPorExemplar($id, (int)$exemplar['id']) : null;
    $obraExemplar = $exemplar ? inventario_repo()->livroConferencia((int)$exemplar['livro_id']) : null;
    $busca = $q !== '' ? inventario_repo()->pesquisarLivros($id, $q) : null;

    // Ficha da obra (&obra=ID, ou o livro do formulário que voltou com erro).
    $obraId = (int)($_GET['obra'] ?? 0);
    if (in_array(admin_param($_POST, 'acao'), ['verificar_obra', 'registrar_exemplar', 'gerar_codigo'], true) && admin_param($_POST, 'origem_form') !== 'nao_listado') {
        $obraId = (int)admin_param($_POST, 'livro_id') ?: $obraId;
    }
    $obra = $obraId ? inventario_repo()->livroConferencia($obraId) : null;
    $obraAlertas = $obra ? inventario_repo()->alertasLivro($obraId) : [];
    $obraExemplares = $obra ? inventario_repo()->exemplaresDaObra($id, $obraId) : [];
    $obraRegistros = $obra ? inventario_repo()->registrosSemExemplar($id, $obraId) : [];
    $obraHistorico = $obra ? obra_verificacao_servico()->historico($obraId) : [];
    $obraVerificada = $obra && $obra['dados_verificados_em'] !== null;
    if ($verificarForm !== null && $erro !== null) {
        $painel = 'verificar';
    }
    if (admin_param($_POST, 'acao') === 'registrar_exemplar' && $erro === null) {
        $registrarForm = null;
    }

    $mostrarNovo = $podeCadastrar && ($novoForm !== null || admin_param($_GET, 'novo') === '1');
    $tokenNovo = $mostrarNovo ? ($novoForm !== null && isset($_SESSION['inv_nao_listado'][admin_param($novoForm, 'token')]) ? admin_param($novoForm, 'token') : conferencia_token_novo($id)) : '';
    $categoriasLista = db_query('SELECT id, nome FROM categorias WHERE arquivado_em IS NULL ORDER BY nome')->fetchAll();
    $resumo = inventario_repo()->resumo($id);
    $recentes = inventario_repo()->recentes($id, 8);
    $locais = localizacoes_servico()->ativas();
    $criadosIds = array_filter(array_map('intval', explode(',', admin_param($_GET, 'criados'))));
    $criados = $criadosIds ? exemplares_repo()->paraEtiquetas($criadosIds) : [];
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a conferência', fisico_erro_tecnico($e, 'conferência do inventário'));
    exit;
}
$emAndamento = $inv['status'] === 'em_andamento';
$localSessao = $sessao['local'];

admin_page_start('inventario', 'Conferência: ' . $inv['titulo'], 'Inventário', 'Conferência digital do acervo físico', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], [$inv['titulo'], admin_url('inventario-detalhes') . '?id=' . $id], ['Conferência']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Conferência <?= fisico_badge_inventario($inv['status']) ?></h1>
        <p><?= e($inv['titulo']) ?> · pesquise a obra, verifique os dados uma vez e registre cada exemplar físico com o seu código BDP</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeCadastrar && $emAndamento): ?><a class="adm-button adm-button--primary" href="<?= e($base . '&novo=1') ?>#nao-listado"><?= icon('plus') ?> Cadastrar livro não listado</a><?php endif; ?>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('inventario-detalhes') . '?id=' . $id) ?>"><?= icon('list-checks') ?> Ver itens e pendências</a>
    </div>
</section>

<?= admin_flash_html() ?>
<?php if ($erro): ?><?= admin_aviso('erro', 'Nada foi gravado.', $erro) ?><?php endif; ?>

<?php $det = admin_url('inventario-detalhes') . '?id=' . $id . '&filtro='; ?>
<section class="adm-card adm-panel adm-progresso-inv" aria-label="Progresso do inventário">
    <div class="adm-progresso-inv__topo">
        <strong><?= (int)$resumo['percentual'] ?>% concluído</strong>
        <span><?= fisico_numero($resumo['encontrados'] + $resumo['nao_localizados']) ?> de <?= fisico_numero($resumo['itens']) ?> itens com resultado</span>
    </div>
    <div class="adm-progress adm-progress--lg" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int)$resumo['percentual'] ?>" aria-label="Percentual concluído"><div class="adm-progress__bar adm-progress__bar--ok" style="width: <?= (int)$resumo['percentual'] ?>%"></div></div>
    <div class="adm-stats adm-stats--6">
        <?= fisico_stat('Total', $resumo['itens'], 'Itens deste inventário', 'list-checks', 'blue', $det . 'todos') ?>
        <?= fisico_stat('Encontrados', $resumo['encontrados'], 'Exemplares achados na estante', 'square-check', 'green', $det . 'encontrados') ?>
        <?= fisico_stat('Pendentes', $resumo['a_conferir'], 'Ainda não conferidos', 'hourglass', 'amber', $det . 'a_conferir') ?>
        <?= fisico_stat('Não localizados', $resumo['nao_localizados'], 'Procurados e não achados', 'book-x', 'red', $det . 'nao_localizados') ?>
        <?= fisico_stat('Com alertas cadastrais', $resumo['com_alertas'], 'Dados a completar', 'triangle-alert', 'indigo', $det . 'com_alertas') ?>
        <?= fisico_stat('Sem código BDP', $resumo['sem_codigo'], 'Linhas da planilha ainda sem exemplar', 'tag', 'muted', $det . 'sem_codigo') ?>
    </div>
    <p class="adm-progresso-inv__obras"><?= icon('circle-check') ?> Obras com dados verificados: <strong><?= fisico_numero($resumo['obras_verificadas']) ?> de <?= fisico_numero($resumo['obras']) ?></strong> · a verificação é da obra; “Encontrado” é de cada exemplar.</p>
</section>

<?php if (!$emAndamento): ?>
    <?= admin_aviso('info', 'Este inventário não está em andamento.', $inv['status'] === 'pausado' ? 'Ele está pausado. Retome-o na página do inventário para continuar a conferência.' : 'A conferência só é possível com o inventário em andamento.') ?>
<?php else: ?>

<div class="adm-conferencia">
    <div class="adm-conferencia__main">
        <section class="adm-card adm-panel adm-leitura" aria-labelledby="titulo-leitura">
            <form method="post" action="<?= e($base) ?>" class="adm-leitura__form" autocomplete="off">
                <?= csrf_campo() ?>
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="ler">
                <label for="leitura" id="titulo-leitura" class="adm-leitura__label"><?= icon('keyboard') ?> Pesquisar livro: código BDP, título, autor ou ISBN</label>
                <div class="adm-leitura__linha">
                    <div class="adm-sugestoes">
                        <input class="adm-input adm-leitura__campo" type="text" id="leitura" name="leitura" maxlength="100" placeholder="BDP-000001 (ou título, autor, ISBN)"<?= $obra || $mostrarNovo ? '' : ' autofocus' ?> data-campo-codigo autocapitalize="off" autocomplete="off" spellcheck="false" aria-describedby="leitura-dica"
                            role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="leitura-sugestoes"
                            data-sugestoes="<?= e(admin_asset('api/inventario/sugestoes.php') . '?id=' . $id) ?>" data-sugestoes-base="<?= e($base) ?>">
                        <ul class="adm-sugestoes__lista" id="leitura-sugestoes" role="listbox" aria-label="Sugestões" hidden></ul>
                    </div>
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('search') ?> Pesquisar</button>
                </div>
                <p class="adm-field__hint" id="leitura-dica">
                    Livro com etiqueta? Digite o código BDP e pressione Enter (maiúsculas ou minúsculas funcionam).
                    <?= $sessao['rapido'] ? '<strong>Modo rápido ligado:</strong> cada código digitado é conferido na hora, com o estado já cadastrado e a localização em conferência.' : '' ?>
                    Sem carimbo ou etiqueta? Pesquise pelo título, autor ou ISBN e abra a obra. O livro não aparece? Use “Cadastrar livro não listado”.
                </p>
            </form>
        </section>

        <?php if ($codigoLido !== ''): ?>
            <section class="adm-card adm-panel" aria-labelledby="titulo-lido">
                <?php if (!$exemplar): ?>
                    <?php if (!EmprestimoRepository::codigoExemplar($codigoLido)): ?>
                        <?= admin_aviso('erro', 'Código incompleto.', CodigoBdp::mensagemInvalido($codigoLido)) ?>
                    <?php else: ?>
                        <?= admin_aviso('erro', 'Código ' . EmprestimoRepository::codigoExemplar($codigoLido) . ' não encontrado.', 'Nenhum exemplar tem este código. Confira se ele foi digitado exatamente como está na etiqueta. Nada foi criado. Se o livro está em mãos e ainda não é um exemplar registrado, pesquise a obra pelo título e use "+ Registrar exemplar".') ?>
                    <?php endif; ?>
                <?php else: ?>
                    <?php if ($obraExemplar): ?>
                        <div class="adm-obra-resumo">
                            <span class="adm-obra-resumo__rotulo">Obra</span>
                            <strong><?= e($obraExemplar['titulo'] ?? Biblioteca\Repositories\InventarioRepository::SEM_TITULO) ?></strong>
                            <?= obra_badge_verificacao($obraExemplar['dados_verificados_em']) ?>
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&obra=' . (int)$obraExemplar['id']) ?>#obra"><?= icon('book-copy') ?> Ver obra e exemplares</a>
                        </div>
                    <?php endif; ?>
                    <?php if ($itemAnterior && $itemAnterior['conferido_em'] !== null): ?>
                        <div class="adm-panel__header"><div><h2 id="titulo-lido"><span class="adm-code"><?= e($exemplar['codigo_individual']) ?></span> já conferido neste inventário</h2>
                            <p><?= e($exemplar['titulo']) ?></p></div></div>
                        <?= admin_aviso('aviso', 'Código já conferido: nada foi gravado de novo.', 'Conferido em ' . admin_data_hora($itemAnterior['conferido_em']) . ($itemAnterior['conferido_por_nome'] ? ' por ' . $itemAnterior['conferido_por_nome'] : '') . '.') ?>
                        <dl class="adm-meta adm-meta--boxed adm-meta--grid">
                            <div><dt>Localização encontrada</dt><dd><?= e(fisico_local($itemAnterior, 'encontrada_')) ?></dd></div>
                            <div><dt>Estado encontrado</dt><dd><?= e(ACERVO_CONSERVACAO[$itemAnterior['estado_encontrado']] ?? '—') ?></dd></div>
                            <div><dt>Observação</dt><dd><?= $itemAnterior['observacao'] ? e($itemAnterior['observacao']) : '—' ?></dd></div>
                            <div><dt>Situação</dt><dd><?= e(inventario_item_situacao($itemAnterior)[0]) ?></dd></div>
                        </dl>
                        <?php if ($podeGerenciar): ?>
                            <details class="adm-acao">
                                <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('pencil') ?> Corrigir esta conferência</summary>
                                <form method="post" action="<?= e($base) ?>" class="adm-acao__form">
                                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="corrigir"><input type="hidden" name="item_id" value="<?= (int)$itemAnterior['id'] ?>">
                                    <div class="adm-form-grid">
                                        <label class="adm-field"><span class="adm-field__sublabel">Estado encontrado</span><?= fisico_select_conservacao('estado_conservacao', (string)$itemAnterior['estado_encontrado']) ?></label>
                                        <label class="adm-field"><span class="adm-field__sublabel">Localização encontrada</span><?= fisico_select_local('localizacao_id', $itemAnterior['localizacao_encontrada_id'] !== null ? (int)$itemAnterior['localizacao_encontrada_id'] : null, 'Manter a registrada', '', $locais) ?></label>
                                        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Observação</span><input class="adm-input" name="observacao" maxlength="500" value="<?= e((string)$itemAnterior['observacao']) ?>"></label>
                                        <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Motivo da correção *</span><input class="adm-input" name="motivo" maxlength="500" required></label>
                                    </div>
                                    <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('save') ?> Salvar correção</button>
                                </form>
                            </details>
                        <?php else: ?>
                            <p class="adm-field__hint">Se a conferência anterior estiver errada, peça a correção a quem gerencia o inventário.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="adm-panel__header"><div>
                            <h2 id="titulo-lido">Exemplar <span class="adm-code"><?= e($exemplar['codigo_individual']) ?></span></h2>
                            <p><?= e($exemplar['autores'] ?: 'Autor não informado') ?> · cadastrado em <?= e(fisico_local($exemplar)) ?> · <?= acervo_badge_situacao($exemplar['situacao']) ?><?= !$itemAnterior ? ' · <strong>fora da lista prevista</strong>' : '' ?></p>
                        </div></div>
                        <?php if ($exemplar['situacao'] === 'baixado'): ?>
                            <?= admin_aviso('aviso', 'Este exemplar foi baixado.', 'A conferência fica registrada como divergência de situação. Exemplares baixados não voltam ao acervo.') ?>
                        <?php elseif ($exemplar['emprestimo_aberto']): ?>
                            <?= admin_aviso('aviso', 'Este exemplar consta como emprestado.', 'A conferência registra a divergência. O inventário não encerra empréstimos: registre a devolução em Empréstimos, se for o caso.') ?>
                        <?php elseif ($exemplar['situacao'] === 'extraviado'): ?>
                            <?= admin_aviso('aviso', 'Este exemplar estava registrado como extraviado.', 'A conferência registra a divergência; a reativação depende da decisão no inventário.') ?>
                        <?php endif; ?>
                        <form method="post" action="<?= e($base) ?>" class="adm-form-section" data-loading-form>
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="conferir"><input type="hidden" name="exemplar_id" value="<?= (int)$exemplar['id'] ?>">
                            <fieldset class="adm-fieldset adm-form-section">
                            <legend class="adm-field__label">Exemplar em mãos</legend>
                            <div class="adm-form-grid">
                                <label class="adm-field"><span class="adm-field__label">Estado encontrado *</span><?= fisico_select_conservacao('estado_conservacao', $exemplar['estado_conservacao']) ?></label>
                                <label class="adm-field"><span class="adm-field__label">Localização encontrada</span>
                                    <?= fisico_select_local('localizacao_id', $localSessao ?? ($exemplar['localizacao_id'] !== null ? (int)$exemplar['localizacao_id'] : null), $exemplar['localizacao_id'] ? 'A mesma do cadastro' : 'Não informar agora', '', $locais) ?>
                                    <span class="adm-field__hint"><?= $exemplar['localizacao_id'] ? 'Se for diferente do cadastro, fica registrada como divergência.' : 'O exemplar não tem localização: a escolhida passa a ser a confirmada.' ?></span></label>
                                <label class="adm-field adm-field--full"><span class="adm-field__label">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: capa solta, estava fora de ordem"></label>
                                <?php if ($podeGerenciar): ?>
                                    <label class="adm-check-confirm adm-field--full"><input type="checkbox" name="aplicar" value="1"> <span>Se houver diferença de estado ou localização, já atualizar o cadastro deste exemplar (decisão registrada agora).</span></label>
                                <?php endif; ?>
                            </div>
                            </fieldset>
                            <p class="adm-field__hint">Os dados bibliográficos são da obra: para corrigi-los, use “Ver obra e exemplares” e depois “Verificar e corrigir dados”.</p>
                            <div class="adm-form-actions adm-form-actions--static">
                                <button class="adm-button adm-button--primary" type="submit"><?= icon('square-check') ?> Salvar e marcar como encontrado</button>
                                <a class="adm-button adm-button--ghost" href="<?= e($base) ?>">Cancelar</a>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($busca !== null): ?>
            <section class="adm-card adm-panel" aria-labelledby="titulo-busca">
                <div class="adm-panel__header"><div><h2 id="titulo-busca">Obras encontradas para “<?= e($q) ?>”</h2>
                    <p>Abra a obra que está em mãos para verificar os dados e registrar os exemplares. Exemplar com etiqueta? Clique no código.</p></div></div>
                <?php if (!$busca['livros']): ?>
                    <div class="adm-empty adm-empty--left"><?= icon('book-x') ?><strong>Nenhum livro encontrado.</strong><span>Tente outra palavra do título, o sobrenome do autor ou o ISBN. Se o livro está em mãos e não consta no sistema, cadastre-o aqui mesmo.</span>
                        <?php if ($podeCadastrar): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '&novo=1&titulo=' . rawurlencode($q)) ?>#nao-listado"><?= icon('plus') ?> Cadastrar livro não listado</a><?php endif; ?></div>
                <?php else: ?>
                    <ul class="adm-resultados adm-resultados--livros">
                        <?php foreach ($busca['livros'] as $l): ?>
                            <li id="livro-<?= (int)$l['id'] ?>">
                                <div>
                                    <strong><?= e($l['titulo']) ?></strong> <?= obra_badge_verificacao($l['dados_verificados_em']) ?><?= (int)$l['alertas'] ? ' <span class="adm-status adm-tone--indigo">' . (int)$l['alertas'] . ' alerta(s)</span>' : '' ?>
                                    <small><?= e($l['autores'] ?: 'Autor não informado') ?><?= $l['isbn'] ? ' · ISBN ' . e($l['isbn']) : ($l['isbn_original'] ? ' · ISBN informado: ' . e($l['isbn_original']) : '') ?><?= $l['edicao'] ? ' · ' . e($l['edicao']) : '' ?></small>
                                    <small class="adm-table__muted">Exemplares físicos: <?= count($l['exemplares']) ?> · Linhas da planilha sem exemplar: <?= count($l['registros']) ?><?= $l['quantidade_planilha'] !== null ? ' · quantidade na planilha: ' . (int)$l['quantidade_planilha'] . ' (só referência)' : '' ?><?= $l['localizacao_antiga'] ? ' · Local antigo: ' . e($l['localizacao_antiga']) : '' ?></small>
                                    <?php if ($l['exemplares']): ?>
                                        <div class="adm-exemplares-chips">
                                            <?php foreach ($l['exemplares'] as $ex): ?>
                                                <?php if ($ex['conferido_em']): ?>
                                                    <span class="adm-chip adm-chip--ok" title="Já encontrado neste inventário"><?= icon('check') ?> <?= e($ex['codigo_individual']) ?></span>
                                                <?php else: ?>
                                                    <a class="adm-chip" href="<?= e($base . '&codigo=' . rawurlencode($ex['codigo_individual'])) ?>"><?= e($ex['codigo_individual']) ?> · <?= e(Biblioteca\Services\ExemplarService::rotuloSituacao($ex['situacao'])) ?></a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '&obra=' . (int)$l['id']) ?>#obra"><?= icon('book-copy') ?> Abrir obra</a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($busca['mais']): ?><p class="adm-field__hint">Há mais resultados. Refine a pesquisa.</p><?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($obraId && !$obra): ?>
            <?= admin_aviso('erro', 'Obra não encontrada.', 'Ela pode ter sido arquivada. Pesquise de novo pelo título, autor ou ISBN.') ?>
        <?php elseif ($obra): ?>
            <?php
            $tituloObra = $obra['titulo'] ?? Biblioteca\Repositories\InventarioRepository::SEM_TITULO;
            $isbnObra = $obra['isbn'] ?: ($obra['isbn_original'] ? $obra['isbn_original'] . ' (como foi informado)' : null);
            $ativos = array_filter($obraExemplares, fn ($x) => $x['situacao'] !== 'baixado');
            $encontradosObra = count(array_filter($ativos, fn ($x) => $x['conferido_em'] !== null));
            ?>
            <?php if ($criados): ?>
                <section class="adm-card adm-panel adm-criados" aria-label="Exemplar registrado agora">
                    <p><?= icon('square-check') ?> Exemplar registrado. Escreva ou confira no carimbo do livro:</p>
                    <ul class="adm-code-list">
                        <?php foreach ($criados as $c): ?><li><a class="adm-code adm-code--grande" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$c['id']) ?>"><?= e($c['codigo_individual']) ?></a></li><?php endforeach; ?>
                    </ul>
                    <div class="adm-actions-inline">
                        <?php if ($podeCadastrar && $obraVerificada): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '&obra=' . (int)$obra['id'] . '&painel=registrar') ?>#exemplares" data-painel-abrir="painel-registrar"><?= icon('plus') ?> Registrar outro exemplar desta obra</a><?php endif; ?>
                        <?php if (auth_pode('etiquetas.gerar')): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('etiquetas') . '?ids=' . implode(',', array_column($criados, 'id'))) ?>"><?= icon('printer') ?> Imprimir etiqueta</a><?php endif; ?>
                    </div>
                </section>
            <?php endif; ?>

            <section class="adm-card adm-panel adm-obra" id="obra" aria-labelledby="titulo-obra">
                <div class="adm-panel__header"><div>
                    <span class="adm-obra__rotulo"><?= icon('book-open') ?> Obra · dados bibliográficos</span>
                    <h2 id="titulo-obra"><?= e($tituloObra) ?> <?= obra_badge_verificacao($obra['dados_verificados_em']) ?></h2>
                    <p><?= $obraVerificada
                        ? 'Verificados em ' . e(admin_data_hora($obra['dados_verificados_em'])) . ($obra['verificado_por_nome'] ? ' por ' . e($obra['verificado_por_nome']) : '') . '. Outras cópias desta obra não precisam ser verificadas de novo.'
                        : 'Os dados desta obra ainda não foram conferidos pela equipe. Confira com o livro em mãos antes de registrar exemplares.' ?></p>
                </div>
                <?php if ($podeEditarLivro): ?>
                    <a class="adm-button <?= $obraVerificada ? 'adm-button--ghost' : 'adm-button--primary' ?>" href="<?= e($base . '&obra=' . (int)$obra['id'] . '&painel=verificar') ?>#obra" data-painel-abrir="painel-verificar"><?= icon('pencil') ?> <?= $obraVerificada ? 'Corrigir dados da obra' : 'Verificar e corrigir dados' ?></a>
                <?php endif; ?>
                </div>
                <dl class="adm-meta adm-meta--boxed adm-meta--grid">
                    <div><dt>Autor(es)</dt><dd><?= $obra['autores'] ? e(implode('; ', $obra['autores'])) : '<span class="adm-table__muted">não informado</span>' ?></dd></div>
                    <div><dt>ISBN</dt><dd><?= $isbnObra ? e($isbnObra) : '<span class="adm-table__muted">não informado</span>' ?></dd></div>
                    <div><dt>Editora</dt><dd><?= $obra['editora'] ? e($obra['editora']) : '<span class="adm-table__muted">não informada</span>' ?></dd></div>
                    <div><dt>Ano</dt><dd><?= $obra['ano_publicacao'] ? (int)$obra['ano_publicacao'] : '<span class="adm-table__muted">não informado</span>' ?></dd></div>
                    <div><dt>Edição</dt><dd><?= $obra['edicao'] ? e($obra['edicao']) : '<span class="adm-table__muted">—</span>' ?></dd></div>
                    <div><dt>Categoria</dt><dd><?= $obra['categoria_nome'] ? e($obra['categoria_nome']) : '<span class="adm-table__muted">sem categoria</span>' ?></dd></div>
                </dl>
                <?php if ($obraAlertas): ?><p class="adm-field__hint"><?= icon('triangle-alert') ?> <?= count($obraAlertas) ?> alerta(s) cadastral(is) aberto(s). Eles aparecem no painel de verificação e não impedem marcar os dados como verificados: dados que não existem podem ficar vazios.</p><?php endif; ?>
                <?php if ($obra['legados']): ?>
                    <p class="adm-field__hint"><?= icon('file-spreadsheet') ?> Importada da planilha: <?= e(implode(' · ', array_map(fn ($r) => 'linha ' . (int)$r['linha'] . ($r['arquivo'] ? ' de “' . $r['arquivo'] . '”' : ''), array_slice($obra['legados'], 0, 5)))) ?><?= count($obra['legados']) > 5 ? ' …' : '' ?>. O vínculo com a planilha original é preservado.</p>
                <?php endif; ?>
                <?php if ($obraHistorico): ?>
                    <details class="adm-acao adm-obra__historico">
                        <summary><?= icon('history') ?> Histórico da verificação (<?= count($obraHistorico) ?>)</summary>
                        <ol class="adm-historico-obra">
                            <?php foreach ($obraHistorico as $h): ?>
                                <li><strong><?= e(ObraVerificacaoService::descrever($h)) ?></strong>
                                    <small class="adm-block-muted"><?= admin_data_hora($h['criado_em']) ?><?= $h['administrador_nome'] ? ' · ' . e($h['administrador_nome']) : '' ?></small>
                                    <?php if ($h['alteracoes']): ?>
                                        <ul class="adm-historico-obra__campos">
                                            <?php foreach ($h['alteracoes'] as $campo => $v): ?>
                                                <li><?= e(ObraVerificacaoService::ROTULOS[$campo] ?? $campo) ?>: <del><?= e((string)($v['antes'] ?? '—') ?: '—') ?></del> → <ins><?= e((string)($v['depois'] ?? '—') ?: '—') ?></ins></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </details>
                <?php endif; ?>
            </section>

            <section class="adm-card adm-panel adm-obra-exemplares" id="exemplares" aria-labelledby="titulo-exemplares">
                <div class="adm-panel__header"><div>
                    <h2 id="titulo-exemplares"><?= icon('book-copy') ?> Exemplares físicos <span class="adm-table__muted">(<?= count($ativos) ?>, <?= $encontradosObra ?> encontrado(s) neste inventário)</span></h2>
                    <p>Cada exemplar tem o seu código BDP e a sua situação no inventário. Vários exemplares usam o mesmo cadastro da obra.</p>
                </div>
                <?php if ($podeCadastrar): ?>
                    <?php if ($obraVerificada): ?>
                        <a class="adm-button adm-button--primary" href="<?= e($base . '&obra=' . (int)$obra['id'] . '&painel=registrar') ?>#exemplares" data-painel-abrir="painel-registrar"><?= icon('plus') ?> Registrar exemplar</a>
                    <?php else: ?>
                        <span class="adm-button adm-button--ghost is-disabled" aria-disabled="true" title="Verifique os dados da obra primeiro"><?= icon('lock') ?> Registrar exemplar</span>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
                <?php if (!$obraVerificada && $podeCadastrar): ?>
                    <?= admin_aviso('info', 'Primeiro, verifique os dados da obra.', 'Clique em “Verificar e corrigir dados”, confira com o livro em mãos e use “Salvar e marcar dados como verificados”. Depois o botão “+ Registrar exemplar” fica disponível.') ?>
                <?php endif; ?>
                <?php if (!$obraExemplares): ?>
                    <div class="adm-empty adm-empty--left"><?= icon('book-copy') ?><strong>Nenhum exemplar físico registrado ainda.</strong><span>Os registros da planilha abaixo não são exemplares: eles só viram exemplar quando alguém registra o livro físico com um código BDP.</span></div>
                <?php else: ?>
                    <ul class="adm-exemplares-obra">
                        <?php foreach ($obraExemplares as $ex): [$rotEx, $tomEx] = inventario_exemplar_status($ex); ?>
                            <li class="<?= in_array((int)$ex['id'], $criadosIds, true) ? 'is-novo' : '' ?>">
                                <span class="adm-code"><?= e($ex['codigo_individual']) ?></span>
                                <span class="adm-status adm-tone--<?= $tomEx ?>"><?= e($rotEx) ?></span>
                                <span class="adm-exemplares-obra__info">
                                    <?= e(fisico_local($ex)) ?> · <?= e(ACERVO_CONSERVACAO[$ex['estado_conservacao']] ?? $ex['estado_conservacao']) ?> · <?= e(Biblioteca\Services\ExemplarService::rotuloSituacao($ex['emprestimo_aberto'] ? 'emprestado' : $ex['situacao'])) ?>
                                    <?= $ex['origem_linha'] ? ' · linha ' . (int)$ex['origem_linha'] . ' da planilha' : '' ?><?= $ex['item_id'] === null && $ex['situacao'] !== 'baixado' ? ' · fora da lista deste inventário' : '' ?>
                                </span>
                                <span class="adm-actions-inline">
                                    <?php if ($ex['conferido_em'] === null && $ex['situacao'] !== 'baixado'): ?>
                                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&codigo=' . rawurlencode($ex['codigo_individual'])) ?>"><?= icon('square-check') ?> Marcar como encontrado</a>
                                    <?php endif; ?>
                                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$ex['id']) ?>"><?= icon('eye') ?> Detalhes</a>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <?php if ($obraRegistros): ?>
                <section class="adm-card adm-panel" id="registros" aria-labelledby="titulo-registros">
                    <div class="adm-panel__header"><div>
                        <h2 id="titulo-registros"><?= icon('file-spreadsheet') ?> Registros da planilha ainda sem exemplar (<?= count($obraRegistros) ?>)</h2>
                        <p>Linhas importadas que ainda não foram confirmadas como livros físicos. Continuam disponíveis para pesquisa e verificação, mas <strong>não contam como exemplares encontrados</strong>. Ao registrar um exemplar, escolha a linha que ele representa.</p>
                    </div></div>
                    <ul class="adm-registros-obra">
                        <?php foreach ($obraRegistros as $reg): [$rotReg, $tomReg] = inventario_item_situacao($reg); ?>
                            <li id="registro-<?= (int)$reg['id'] ?>">
                                <span><?= icon('file-spreadsheet') ?> <strong><?= $reg['origem_linha'] ? 'Linha ' . (int)$reg['origem_linha'] . ' da planilha' : 'Registro importado sem código' ?></strong>
                                    <?= $reg['origem_arquivo'] ? '<small class="adm-table__muted">“' . e($reg['origem_arquivo']) . '”</small>' : '' ?></span>
                                <span class="adm-status adm-tone--<?= $tomReg ?>"><?= e($rotReg) ?></span>
                                <small class="adm-table__muted"><?= $reg['localizacao_antiga'] ? 'Local antigo: ' . e($reg['localizacao_antiga']) . ' · ' : '' ?><?= $reg['quantidade_informada'] !== null ? 'quantidade na planilha: ' . (int)$reg['quantidade_informada'] . ' (só referência)' : 'sem quantidade na planilha' ?>
                                    <?= $reg['nao_localizado_em'] ? ' · não localizado em ' . e(admin_data_hora($reg['nao_localizado_em'])) . ($reg['nao_localizado_por_nome'] ? ' por ' . e($reg['nao_localizado_por_nome']) : '') . ($reg['nao_localizado_obs'] ? ' (' . e($reg['nao_localizado_obs']) . ')' : '') : '' ?></small>
                                <span class="adm-actions-inline">
                                    <?php if (!$reg['nao_localizado_em']): ?>
                                        <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Marcar esta linha da planilha como não localizada? Você pode desfazer depois.">
                                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="nao_localizado"><input type="hidden" name="item_id" value="<?= (int)$reg['id'] ?>"><input type="hidden" name="livro_id" value="<?= (int)$obra['id'] ?>">
                                            <input class="adm-input adm-input--sm" name="observacao" maxlength="500" placeholder="Onde foi procurado (opcional)" aria-label="Onde foi procurado (opcional)">
                                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('book-x') ?> Marcar como não localizado</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="<?= e($base) ?>" class="adm-inline-form">
                                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="voltar_pendente"><input type="hidden" name="item_id" value="<?= (int)$reg['id'] ?>">
                                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('rotate-ccw') ?> Voltar para pendente</button>
                                        </form>
                                    <?php endif; ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($mostrarNovo): ?>
            <?php $nv = $novoForm ?? ['titulo' => mb_substr(admin_param($_GET, 'titulo'), 0, 255)]; ?>
            <section class="adm-card adm-panel" id="nao-listado" aria-labelledby="titulo-nao-listado">
                <div class="adm-panel__header"><div><h2 id="titulo-nao-listado"><?= icon('book-plus') ?> Cadastrar livro não listado</h2>
                    <p>Para o livro que está em mãos e não aparece na pesquisa. Primeiro a <strong>obra</strong> (dados bibliográficos, todos opcionais), depois o <strong>exemplar</strong> físico com o código BDP. Tudo é gravado de uma vez.</p></div></div>
                <form method="post" action="<?= e($base) ?>#nao-listado" class="adm-form-section" data-loading-form>
                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="nao_listado"><input type="hidden" name="token" value="<?= e($tokenNovo) ?>">
                    <?php if ($correspondencias): ?>
                        <div class="adm-correspondencias" role="group" aria-labelledby="titulo-corresp">
                            <p id="titulo-corresp"><?= icon('triangle-alert') ?> <strong>Encontramos <?= count($correspondencias) ?> obra(s) parecida(s) no sistema.</strong> Confira antes de cadastrar. Nada é unido automaticamente: edições diferentes podem ter o mesmo título.</p>
                            <ul class="adm-resultados">
                                <?php foreach ($correspondencias as $c): ?>
                                    <li><span><strong><?= e($c['titulo']) ?></strong> · <?= e($c['autores'] ?: 'Autor não informado') ?><?= $c['isbn'] ? ' · ISBN ' . e($c['isbn']) : '' ?><?= $c['editora'] ? ' · ' . e($c['editora']) : '' ?><?= $c['ano_publicacao'] ? ' · ' . (int)$c['ano_publicacao'] : '' ?>
                                        <small class="adm-block-muted"><?= e($c['motivo']) ?> · <?= (int)$c['exemplares'] ?> exemplar(es)</small></span>
                                        <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&obra=' . (int)$c['id']) ?>#obra"><?= icon('book-copy') ?> É esta obra: abrir e registrar exemplar</a></li>
                                <?php endforeach; ?>
                            </ul>
                            <label class="adm-check-confirm"><input type="checkbox" name="escolha" value="novo" required> <span><strong>Nenhuma destas: é uma obra diferente.</strong> Cadastrar uma obra nova com os dados abaixo.</span></label>
                        </div>
                    <?php endif; ?>
                    <?php $lv = ['titulo' => $nv['titulo'] ?? '', 'subtitulo' => $nv['subtitulo'] ?? '', 'autores' => $nv['autores'] ?? '', 'isbn' => $nv['isbn'] ?? '', 'editora' => $nv['editora'] ?? '', 'ano_publicacao' => $nv['ano_publicacao'] ?? '', 'edicao' => $nv['edicao'] ?? '', 'idioma' => $nv['idioma'] ?? '', 'assunto' => $nv['assunto'] ?? '', 'categoria_id' => $nv['categoria_id'] ?? ''];
                          $alertasLv = []; $editavelLv = true; include __DIR__ . '/includes/form-conferencia-livro.php'; ?>
                    <?php $rx = $nv + ['localizacao_id' => (string)($localSessao ?? '')]; $rxLivroId = 0; $rxRegistros = []; $rxOrigem = 'nao_listado'; include __DIR__ . '/includes/form-registrar-exemplar.php'; ?>
                    <p class="adm-field__hint">Ao clicar no botão principal, você confirma que conferiu os dados da obra com o livro em mãos: ela já fica com <strong>Dados verificados</strong> e o primeiro exemplar é registrado. Se ainda não conferiu, cadastre só a obra; o exemplar fica para depois.</p>
                    <div class="adm-form-actions adm-form-actions--static">
                        <?php if ($podeEditarLivro): ?><button class="adm-button adm-button--primary" type="submit" name="marcar_verificado" value="1"><?= icon('square-check') ?> Salvar, marcar dados como verificados e registrar exemplar</button><?php endif; ?>
                        <button class="adm-button adm-button--ghost" type="submit" name="marcar_verificado" value="0" formnovalidate><?= icon('save') ?> Cadastrar só a obra (dados não verificados)</button>
                        <a class="adm-button adm-button--ghost" href="<?= e($base) ?>">Cancelar</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>

    <aside class="adm-conferencia__side">
        <section class="adm-card adm-panel adm-form-section" aria-labelledby="titulo-preferencias">
            <h2 id="titulo-preferencias" class="adm-panel__title">Localização em conferência</h2>
            <form method="post" action="<?= e($base) ?>">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="local">
                <label class="adm-field"><span class="adm-field__sublabel">Estante ou prateleira que está sendo conferida</span>
                    <?= fisico_select_local('local_conferencia', $localSessao, 'Nenhuma (escolher em cada conferência)', 'local-conferencia', $locais) ?></label>
                <label class="adm-check-confirm"><input type="checkbox" name="rapido" value="1"<?= $sessao['rapido'] ? ' checked' : '' ?>> <span>Modo rápido: conferir assim que o código for digitado, sem abrir o formulário</span></label>
                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('save') ?> Aplicar</button>
            </form>
            <?php if (!$locais): ?><p class="adm-field__hint">Nenhuma localização cadastrada. <a class="adm-link" href="<?= e(admin_url('localizacoes')) ?>">Cadastrar localizações</a></p><?php endif; ?>
        </section>

        <section class="adm-card adm-panel adm-guia-obra" aria-labelledby="titulo-guia">
            <h2 id="titulo-guia" class="adm-panel__title">Obra × exemplar</h2>
            <ol>
                <li>Pesquise e abra a <strong>obra</strong>.</li>
                <li><strong>Verificar e corrigir dados</strong> → <strong>Salvar e marcar dados como verificados</strong> (uma vez por obra).</li>
                <li><strong>+ Registrar exemplar</strong> → <strong>Gerar código BDP</strong> → escreva o código no carimbo → salve como <strong>Encontrado</strong>.</li>
                <li>Outra cópia? <strong>+ Registrar exemplar</strong> de novo.</li>
            </ol>
        </section>

        <section class="adm-card adm-panel" aria-labelledby="titulo-recentes">
            <h2 id="titulo-recentes" class="adm-panel__title">Últimas conferências</h2>
            <?php if (!$recentes): ?>
                <p class="adm-field__hint">Nenhuma conferência registrada ainda.</p>
            <?php else: ?>
                <ol class="adm-recentes">
                    <?php foreach ($recentes as $r): ?>
                        <?php [$rot, $tom] = inventario_item_situacao($r); ?>
                        <li><a class="adm-code" href="<?= e($r['codigo_individual'] ? $base . '&codigo=' . rawurlencode($r['codigo_individual']) : $base . '&obra=' . (int)$r['livro_id']) ?>"><?= e($r['codigo_individual'] ?? 'Sem código') ?></a>
                            <span class="adm-status adm-tone--<?= $tom ?>"><?= e($rot) ?></span>
                            <small class="adm-block-muted"><?= e(mb_strimwidth($r['titulo'], 0, 60, '…')) ?> · <?= admin_data_hora($r['conferido_em']) ?></small></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </aside>
</div>

<?php if ($obra && $podeEditarLivro): ?>
    <?php
    $vf = $verificarForm;
    $lv = $vf !== null
        ? ['titulo' => $vf['titulo'] ?? '', 'subtitulo' => $vf['subtitulo'] ?? '', 'autores' => $vf['autores'] ?? '', 'isbn' => $vf['isbn'] ?? '', 'editora' => $vf['editora'] ?? '', 'ano_publicacao' => $vf['ano_publicacao'] ?? '', 'edicao' => $vf['edicao'] ?? '', 'idioma' => $vf['idioma'] ?? '', 'assunto' => $vf['assunto'] ?? '', 'categoria_id' => $vf['categoria_id'] ?? '']
        : $obra;
    $alertasLv = $obraAlertas; $editavelLv = true;
    ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-verificar" aria-labelledby="painel-verificar-titulo"<?= $painel === 'verificar' ? ' open data-painel-auto' : '' ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base . '&obra=' . (int)$obra['id']) ?>#obra" data-loading-form>
            <header class="adm-modal__header">
                <div><h2 id="painel-verificar-titulo"><?= icon('pencil') ?> Verificar e corrigir dados</h2>
                    <p><?= e($tituloObra) ?> · <?= e(ObraVerificacaoService::rotulo($obra['dados_verificados_em'])) ?></p></div>
                <a class="adm-modal__close" href="<?= e($base . '&obra=' . (int)$obra['id']) ?>#obra" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a>
            </header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="verificar_obra"><input type="hidden" name="livro_id" value="<?= (int)$obra['id'] ?>">
                <?php if ($erro && $painel === 'verificar'): ?><?= admin_aviso('erro', 'Nada foi gravado.', $erro) ?><?php endif; ?>
                <p class="adm-field__hint">Confira com o livro em mãos (capa, folha de rosto, ficha catalográfica). Corrija o que estiver errado e preencha o que estiver disponível. <strong>Nenhum campo é obrigatório</strong>: o que o livro não informa (por exemplo, ISBN) pode ficar vazio mesmo depois da verificação.</p>
                <?php include __DIR__ . '/includes/form-conferencia-livro.php'; ?>
                <?php if ($obraVerificada): ?>
                    <p class="adm-field__hint"><?= icon('info') ?> Esta obra já está verificada. Se você mudar título, subtítulo, autor, ISBN, editora, ano ou edição e clicar em “Salvar alterações”, ela volta para <strong>Dados não verificados</strong>. Toda alteração fica no histórico.</p>
                <?php endif; ?>
            </div>
            <footer class="adm-modal__footer">
                <a class="adm-button adm-button--ghost" href="<?= e($base . '&obra=' . (int)$obra['id']) ?>#obra" data-painel-fechar>Fechar</a>
                <button class="adm-button adm-button--ghost" type="submit" name="marcar_verificado" value="0"><?= icon('save') ?> Salvar alterações</button>
                <button class="adm-button adm-button--primary" type="submit" name="marcar_verificado" value="1"><?= icon('square-check') ?> Salvar e marcar dados como verificados</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($obra && $podeCadastrar && $obraVerificada): ?>
    <?php
    $pendentesVinc = array_values(array_filter($obraRegistros, fn ($r) => $r['conferido_em'] === null && $r['decisao'] === null));
    $rx = ($registrarForm ?? []) + ['localizacao_id' => (string)($localSessao ?? '')];
    $rxLivroId = (int)$obra['id']; $rxRegistros = $pendentesVinc; $rxOrigem = 'registrar';
    ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-registrar" aria-labelledby="painel-registrar-titulo"<?= $painel === 'registrar' ? ' open data-painel-auto' : '' ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base . '&obra=' . (int)$obra['id']) ?>#exemplares" data-loading-form>
            <header class="adm-modal__header">
                <div><h2 id="painel-registrar-titulo"><?= icon('plus') ?> Registrar exemplar</h2>
                    <p><?= e($tituloObra) ?> · <?= count($ativos) ?> exemplar(es) já registrado(s)</p></div>
                <a class="adm-modal__close" href="<?= e($base . '&obra=' . (int)$obra['id']) ?>#exemplares" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a>
            </header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="registrar_exemplar"><input type="hidden" name="livro_id" value="<?= (int)$obra['id'] ?>">
                <?php if ($erro && $painel === 'registrar'): ?><?= admin_aviso('erro', 'Nada foi gravado.', $erro) ?><?php endif; ?>
                <p class="adm-field__hint">Os dados bibliográficos já estão verificados: aqui você registra só o livro físico que está em mãos. Uma cópia por vez, cada uma com o seu código BDP.</p>
                <?php include __DIR__ . '/includes/form-registrar-exemplar.php'; ?>
            </div>
            <footer class="adm-modal__footer">
                <a class="adm-button adm-button--ghost" href="<?= e($base . '&obra=' . (int)$obra['id']) ?>#exemplares" data-painel-fechar>Cancelar</a>
                <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar exemplar</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);

