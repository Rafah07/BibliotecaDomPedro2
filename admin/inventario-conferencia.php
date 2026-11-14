<?php
/**
 * Conferência do inventário em andamento.
 *
 * - A identificação é pela DIGITAÇÃO do código BDP escrito na etiqueta (ex.: BDP-000001),
 *   seguida de Enter. Maiúsculas/minúsculas e espaços nas pontas não importam; o código
 *   precisa estar completo (busca exata, nunca aproximada). Texto que não é código pesquisa
 *   livros por título, autor ou ISBN.
 * - Código conferido de novo: aviso com os dados anteriores; nada é gravado de novo.
 *   A correção é uma ação separada (inventario.gerenciar), com motivo.
 * - Código desconhecido não cria nada. Exemplar novo só com o botão "Cadastrar exemplar"
 *   e a confirmação de que o livro está em mãos.
 * - "Modo rápido" confere ao digitar o código, com o estado já cadastrado e a localização em conferência.
 * - Esta é a ÚNICA página de conferência e correção do acervo: aqui a equipe pesquisa o livro,
 *   corrige os dados bibliográficos (todos opcionais), registra o código BDP do carimbo e clica em
 *   "Salvar e marcar como encontrado" — o cadastro e o inventário são gravados na mesma transação.
 * - Registros importados de planilha ainda sem exemplar abrem por "&item=ID". "+ Cadastrar livro
 *   não listado" cria livro + exemplar + item Encontrado, depois de mostrar livros parecidos.
 *   O formulário leva um token de uso único (clique duplo não cria dois livros) e o código BDP é único.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Services\AcervoException;
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
$fx = [];
$novoForm = null;          // valores do formulário "livro não listado" após um erro ou na etapa de correspondências
$correspondencias = null;  // livros parecidos encontrados antes de cadastrar

/** Token de uso único do formulário "livro não listado" (protege contra clique duplo e reenvio). */
function conferencia_token_novo(int $inventarioId): string
{
    $t = bin2hex(random_bytes(16));
    $_SESSION['inv_nao_listado'][$t] = $inventarioId;
    $_SESSION['inv_nao_listado'] = array_slice($_SESSION['inv_nao_listado'], -20, null, true);
    return $t;
}

/** Dados físicos do formulário (código BDP do carimbo, estado, localização e observação). */
function conferencia_fisico(array $post, ?int $localPadrao): array
{
    return [
        'codigo' => mb_substr(admin_param($post, 'codigo_bdp'), 0, 30),
        'estado_conservacao' => admin_param($post, 'estado_conservacao', 'bom'),
        'localizacao_id' => admin_param($post, 'localizacao_id') !== '' ? admin_param($post, 'localizacao_id') : $localPadrao,
        'observacao' => mb_substr(admin_param($post, 'observacao'), 0, 500),
    ];
}

/** Grava as correções do livro (se o formulário trouxe os campos e o perfil pode editar). */
function conferencia_salvar_livro(int $livroId, array $post, int $usuarioId, bool $pode): ?array
{
    if (!$pode || admin_param($post, 'editar_livro') !== '1') {
        return null;
    }
    $cad = conferencia_cadastro();
    return $cad->atualizarLivro($livroId, $cad->dados($post), $usuarioId, admin_param($post, 'confirmar_alertas') === '1');
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
                // "Salvar e marcar como encontrado": correção do livro + conferência, tudo ou nada.
                $exId = (int)admin_param($_POST, 'exemplar_id');
                $item = conferencia_cadastro()->emTransacao(function () use ($id, $exId, $usuario, $podeGerenciar, $podeEditarLivro) {
                    $ex = exemplares_repo()->buscar($exId);
                    if (!$ex) {
                        throw new AcervoException('Exemplar não encontrado.');
                    }
                    conferencia_salvar_livro((int)$ex['livro_id'], $_POST, $usuario['id'], $podeEditarLivro);
                    return inventario_servico()->conferir($id, $exId, [
                        'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                        'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                        'observacao' => admin_param($_POST, 'observacao'),
                        'aplicar' => admin_param($_POST, 'aplicar') === '1',
                    ], $usuario['id'], $podeGerenciar);
                });
                admin_flash(...conferencia_mensagem($item));
                admin_redirecionar($base);
            case 'encontrar':
                // Registro da planilha (sem exemplar) achado na estante: corrige o livro, cria o
                // exemplar com o código do carimbo e marca Encontrado, em uma transação.
                $itemId = (int)admin_param($_POST, 'item_id');
                $res = conferencia_cadastro()->emTransacao(function () use ($id, $itemId, $usuario, $podeEditarLivro, $sessao) {
                    $it = inventario_repo()->item($itemId);
                    if (!$it || (int)$it['inventario_id'] !== $id) {
                        throw new AcervoException('Registro não encontrado neste inventário.');
                    }
                    if ($it['conferido_em'] !== null) {
                        throw new ConferenciaDuplicadaException('Este registro já foi marcado como encontrado' . ($it['codigo_individual'] ? ' (código ' . $it['codigo_individual'] . ')' : '') . '. Nada foi gravado de novo.', $it);
                    }
                    conferencia_salvar_livro((int)$it['livro_id'], $_POST, $usuario['id'], $podeEditarLivro);
                    return inventario_servico()->encontrarRegistro($itemId, conferencia_fisico($_POST, $sessao['local']), $usuario['id']);
                });
                admin_flash('sucesso', 'Encontrado: ' . $res['codigo'] . '.', 'Cadastro e inventário atualizados. Confira se o carimbo do livro mostra este código' . (admin_param($_POST, 'codigo_bdp') === '' ? ' (o campo ficou vazio, então o sistema gerou o próximo código livre: escreva-o no livro).' : '.'));
                admin_redirecionar($base . '&criados=' . (int)$res['exemplar_id']);
            case 'salvar_livro':
                fisico_exigir('livros.editar');
                $livroAlvo = (int)admin_param($_POST, 'livro_id');
                $r = conferencia_salvar_livro($livroAlvo, $_POST, $usuario['id'], true);
                admin_flash('sucesso', $r && $r['alteracoes'] ? 'Informações do livro salvas.' : 'Nenhuma informação alterada.',
                    $r ? ($r['alertas_abertos'] ? $r['alertas_abertos'] . ' alerta(s) cadastral(is) continuam abertos.' : 'Nenhum alerta cadastral aberto.') : '');
                $voltar = admin_param($_POST, 'voltar');
                if ($voltar === 'corrigir') {
                    admin_redirecionar($base . '&' . http_build_query(array_filter(['corrigir' => $livroAlvo, 'q' => admin_param($_POST, 'q')])) . '#corrigir-livro');
                }
                admin_redirecionar($base . ($voltar !== '' ? '&' . http_build_query(['item' => (int)admin_param($_POST, 'item_id')]) : ''));
            case 'nao_localizado':
                $n = inventario_servico()->marcarNaoLocalizados($id, [(int)admin_param($_POST, 'item_id')], admin_param($_POST, 'observacao'), $usuario['id']);
                admin_flash($n ? 'sucesso' : 'aviso', $n ? 'Marcado como não localizado.' : 'Nada foi alterado.', $n ? 'Se o livro aparecer depois, pesquise-o e marque como encontrado, ou use "Voltar para pendente".' : 'O item já tinha resultado ou está emprestado.');
                admin_redirecionar($base . '&item=' . (int)admin_param($_POST, 'item_id'));
            case 'voltar_pendente':
                $itemId = (int)admin_param($_POST, 'item_id');
                $it = inventario_repo()->item($itemId);
                if ($it && $it['conferido_em'] !== null) {
                    fisico_exigir('inventario.gerenciar');
                }
                inventario_servico()->voltarPendente($itemId, $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'O item voltou para Pendente.', 'Ele aparece de novo entre os livros a conferir.');
                admin_redirecionar($base . ($it && $it['exemplar_id'] === null ? '&item=' . $itemId : ''));
            case 'nao_listado':
                fisico_exigir('exemplares.gerenciar');
                $token = admin_param($_POST, 'token');
                $feito = $_SESSION['inv_nao_listado_feito'][$token] ?? null;
                if ($feito) {
                    admin_flash('aviso', 'Este livro já foi cadastrado (' . $feito['codigo'] . ').', 'O formulário foi enviado de novo (clique duplo ou atualização da página). Nada foi gravado outra vez.');
                    admin_redirecionar($base . '&criados=' . (int)$feito['exemplar_id']);
                }
                if (!isset($_SESSION['inv_nao_listado'][$token])) {
                    throw new AcervoException('O formulário expirou. Abra "+ Cadastrar livro não listado" de novo e confira se o livro já não foi cadastrado.');
                }
                $novoForm = $_POST;
                $cad = conferencia_cadastro();
                $dadosNovo = $cad->dados($_POST);
                $fisico = conferencia_fisico($_POST, $sessao['local']);
                $escolha = admin_param($_POST, 'escolha'); // '' = ainda não viu as correspondências; 'novo' ou id do livro existente
                if ($escolha === '') {
                    $correspondencias = $cad->correspondencias($dadosNovo);
                    if ($correspondencias) {
                        break; // mostra os livros parecidos antes de gravar
                    }
                    $escolha = 'novo';
                }
                $res = $cad->cadastrarNaoListado($id, $dadosNovo, $fisico, $usuario['id'], $escolha === 'novo' ? null : (int)$escolha);
                unset($_SESSION['inv_nao_listado'][$token]);
                $_SESSION['inv_nao_listado_feito'][$token] = ['codigo' => $res['codigo'], 'exemplar_id' => $res['exemplar_id']];
                $_SESSION['inv_nao_listado_feito'] = array_slice($_SESSION['inv_nao_listado_feito'], -20, null, true);
                admin_flash('sucesso', ($res['novo_livro'] ? 'Livro cadastrado e marcado como encontrado: ' : 'Outro exemplar cadastrado e marcado como encontrado: ') . $res['codigo'] . '.',
                    $res['alertas'] ? $res['alertas'] . ' alerta(s) cadastral(is) ficaram registrados para completar depois.' : 'Sem alertas cadastrais.');
                admin_redirecionar($base . '&criados=' . (int)$res['exemplar_id']);
            case 'corrigir':
                fisico_exigir('inventario.gerenciar');
                $item = inventario_servico()->corrigir((int)admin_param($_POST, 'item_id'), [
                    'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                    'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                    'observacao' => admin_param($_POST, 'observacao'),
                ], $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Conferência de ' . $item['codigo_individual'] . ' corrigida.', 'A correção ficou registrada no histórico, com o motivo.');
                admin_redirecionar($base);
            case 'cadastrar':
                fisico_exigir('exemplares.gerenciar');
                $fx = fisico_dados_exemplar($_POST);
                if (admin_param($_POST, 'confirmar') !== '1') {
                    throw new AcervoException('Marque a confirmação de que o exemplar está fisicamente com você.');
                }
                $dados = $fx;
                $dados['localizacao_id'] = fisico_resolver_local($fx, $usuario['id']);
                $criados = inventario_servico()->cadastrarExemplar($id, (int)admin_param($_POST, 'livro_id'), $dados, $usuario['id'], (int)$fx['quantidade']);
                admin_flash('sucesso', count($criados) === 1 ? 'Exemplar cadastrado e conferido: ' . $criados[0]['codigo'] . '.' : count($criados) . ' exemplares cadastrados e conferidos: ' . implode(', ', array_column($criados, 'codigo')) . '.',
                    'Escreva ou cole a etiqueta com este código no livro. As etiquetas podem ser impressas depois em Etiquetas.');
                admin_redirecionar($base . '&criados=' . implode(',', array_column($criados, 'id')));
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
    $codigoLido = mb_substr(admin_param($_GET, 'codigo'), 0, 30);
    $q = mb_substr(admin_param($_GET, 'q'), 0, 100);
    // "Corrigir dados do livro": abre a ficha bibliográfica de qualquer livro aqui mesmo no Inventário.
    $corrigirId = (int)(admin_param($_POST, 'voltar') === 'corrigir' ? admin_param($_POST, 'livro_id') : admin_param($_GET, 'corrigir'));
    $livroId = $corrigirId ? 0 : (int)($_POST['livro_id'] ?? $_GET['livro'] ?? 0);
    $exemplar = $codigoLido !== '' ? exemplares_repo()->porCodigo($codigoLido) : null;
    $itemAnterior = $exemplar ? inventario_repo()->itemPorExemplar($id, (int)$exemplar['id']) : null;
    $busca = $q !== '' ? inventario_repo()->pesquisarLivros($id, $q) : null;
    $livroCadastro = $livroId && $podeCadastrar ? inventario_repo()->livroParaCadastro($livroId) : null;
    $itemRegistro = null;
    $itemId = (int)admin_param($_GET, 'item');
    if ($itemId) {
        $itemRegistro = inventario_repo()->item($itemId);
        if ($itemRegistro && (int)$itemRegistro['inventario_id'] !== $id) {
            $itemRegistro = null;
        }
        if ($itemRegistro && $itemRegistro['exemplar_id'] !== null && $codigoLido === '') {
            admin_redirecionar($base . '&codigo=' . rawurlencode((string)$itemRegistro['codigo_individual']));
        }
    }
    $livroLv = null;
    if ($itemRegistro) {
        $livroLv = inventario_repo()->livroConferencia((int)$itemRegistro['livro_id']);
    } elseif ($exemplar && !($itemAnterior && $itemAnterior['conferido_em'] !== null)) {
        $livroLv = inventario_repo()->livroConferencia((int)$exemplar['livro_id']);
    }
    $alertasLv = $livroLv ? inventario_repo()->alertasLivro((int)$livroLv['id']) : [];
    $livroCorrigir = $corrigirId && $podeEditarLivro && !$livroLv ? inventario_repo()->livroConferencia($corrigirId) : null;
    $alertasCorrigir = $livroCorrigir ? inventario_repo()->alertasLivro($corrigirId) : [];
    $itensCorrigir = $livroCorrigir ? inventario_repo()->itensDoLivro($id, $corrigirId) : [];
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
        <p><?= e($inv['titulo']) ?> · pesquise o livro, corrija as informações, registre o código BDP e marque como encontrado</p>
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
        <?= fisico_stat('Encontrados', $resumo['encontrados'], 'Achados na estante', 'square-check', 'green', $det . 'encontrados') ?>
        <?= fisico_stat('Pendentes', $resumo['a_conferir'], 'Ainda não conferidos', 'hourglass', 'amber', $det . 'a_conferir') ?>
        <?= fisico_stat('Não localizados', $resumo['nao_localizados'], 'Procurados e não achados', 'book-x', 'red', $det . 'nao_localizados') ?>
        <?= fisico_stat('Com alertas cadastrais', $resumo['com_alertas'], 'Dados a completar', 'triangle-alert', 'indigo', $det . 'com_alertas') ?>
        <?= fisico_stat('Sem código BDP', $resumo['sem_codigo'], 'Registros da planilha a conferir', 'tag', 'muted', $det . 'sem_codigo') ?>
    </div>
</section>

<?php if (!$emAndamento): ?>
    <?= admin_aviso('info', 'Este inventário não está em andamento.', $inv['status'] === 'pausado' ? 'Ele está pausado. Retome-o na página do inventário para continuar a conferência.' : 'A conferência só é possível com o inventário em andamento.') ?>
<?php else: ?>

<?php if ($criados): ?>
    <section class="adm-card adm-panel adm-criados" aria-label="Exemplares cadastrados agora">
        <ul class="adm-code-list">
            <?php foreach ($criados as $c): ?><li><a class="adm-code" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$c['id']) ?>"><?= e($c['codigo_individual']) ?></a></li><?php endforeach; ?>
        </ul>
        <?php if (auth_pode('etiquetas.gerar')): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('etiquetas') . '?ids=' . implode(',', array_column($criados, 'id'))) ?>"><?= icon('printer') ?> Imprimir etiqueta(s)</a><?php endif; ?>
    </section>
<?php endif; ?>

<div class="adm-conferencia">
    <div class="adm-conferencia__main">
        <section class="adm-card adm-panel adm-leitura" aria-labelledby="titulo-leitura">
            <form method="post" action="<?= e($base) ?>" class="adm-leitura__form" autocomplete="off">
                <?= csrf_campo() ?>
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="ler">
                <label for="leitura" id="titulo-leitura" class="adm-leitura__label"><?= icon('keyboard') ?> Pesquisar livro: código BDP, título, autor ou ISBN</label>
                <div class="adm-leitura__linha">
                    <div class="adm-sugestoes">
                        <input class="adm-input adm-leitura__campo" type="text" id="leitura" name="leitura" maxlength="100" placeholder="BDP-000001 (ou título, autor, ISBN)" autofocus data-campo-codigo autocapitalize="off" autocomplete="off" spellcheck="false" aria-describedby="leitura-dica"
                            role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="leitura-sugestoes"
                            data-sugestoes="<?= e(admin_asset('api/inventario/sugestoes.php') . '?id=' . $id) ?>" data-sugestoes-base="<?= e($base) ?>">
                        <ul class="adm-sugestoes__lista" id="leitura-sugestoes" role="listbox" aria-label="Sugestões" hidden></ul>
                    </div>
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('search') ?> Pesquisar</button>
                </div>
                <p class="adm-field__hint" id="leitura-dica">
                    Informe o código BDP escrito na etiqueta e pressione Enter. Letras maiúsculas ou minúsculas funcionam.
                    <?= $sessao['rapido'] ? '<strong>Modo rápido ligado:</strong> cada código digitado é conferido na hora, com o estado já cadastrado e a localização em conferência.' : 'Depois, confira o livro encontrado, o estado e a localização e confirme a identificação.' ?>
                    Sem carimbo ou etiqueta? Pesquise pelo título, autor ou ISBN. O livro não aparece? Use “Cadastrar livro não listado”.
                </p>
            </form>
        </section>

        <?php if ($codigoLido !== ''): ?>
            <section class="adm-card adm-panel" aria-labelledby="titulo-lido">
                <?php if (!$exemplar): ?>
                    <?php if (!EmprestimoRepository::codigoExemplar($codigoLido)): ?>
                        <?= admin_aviso('erro', 'Código incompleto.', CodigoBdp::mensagemInvalido($codigoLido)) ?>
                    <?php else: ?>
                        <?= admin_aviso('erro', 'Código ' . EmprestimoRepository::codigoExemplar($codigoLido) . ' não encontrado.', 'Nenhum exemplar tem este código. Confira se ele foi digitado exatamente como está na etiqueta. Nada foi criado. Se o livro está em mãos e não tem código, pesquise pelo título e use "Cadastrar exemplar".') ?>
                    <?php endif; ?>
                <?php elseif ($itemAnterior && $itemAnterior['conferido_em'] !== null): ?>
                    <div class="adm-panel__header"><div><h2 id="titulo-lido"><span class="adm-code"><?= e($exemplar['codigo_individual']) ?></span> já conferido neste inventário</h2>
                        <p><?= e($exemplar['titulo']) ?></p></div>
                        <?php if ($podeEditarLivro): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '&corrigir=' . (int)$exemplar['livro_id']) ?>#corrigir-livro"><?= icon('pencil') ?> Corrigir dados do livro</a><?php endif; ?></div>
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
                        <h2 id="titulo-lido"><span class="adm-code"><?= e($exemplar['codigo_individual']) ?></span> · <?= e($exemplar['titulo']) ?></h2>
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
                        <?php if ($livroLv): ?>
                            <?php $lv = $livroLv; $editavelLv = $podeEditarLivro; include __DIR__ . '/includes/form-conferencia-livro.php'; ?>
                        <?php endif; ?>
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
                        <div class="adm-form-actions adm-form-actions--static">
                            <button class="adm-button adm-button--primary" type="submit"><?= icon('square-check') ?> Salvar e marcar como encontrado</button>
                            <?php if ($podeEditarLivro && $livroLv): ?><button class="adm-button adm-button--ghost" type="submit" name="acao" value="salvar_livro" formnovalidate><?= icon('save') ?> Salvar só as correções</button><input type="hidden" name="livro_id" value="<?= (int)$livroLv['id'] ?>"><input type="hidden" name="voltar" value="corrigir"><?php endif; ?>
                            <a class="adm-button adm-button--ghost" href="<?= e($base) ?>">Cancelar</a>
                        </div>
                    </form>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($busca !== null): ?>
            <section class="adm-card adm-panel" aria-labelledby="titulo-busca">
                <div class="adm-panel__header"><div><h2 id="titulo-busca">Livros encontrados para “<?= e($q) ?>”</h2>
                    <p>Escolha o exemplar ou o registro da planilha que está em mãos. Os registros sem código BDP abrem para corrigir os dados e marcar como encontrado.</p></div></div>
                <?php if (!$busca['livros']): ?>
                    <div class="adm-empty adm-empty--left"><?= icon('book-x') ?><strong>Nenhum livro encontrado.</strong><span>Tente outra palavra do título, o sobrenome do autor ou o ISBN. Se o livro está em mãos e não consta no sistema, cadastre-o aqui mesmo.</span>
                        <?php if ($podeCadastrar): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '&novo=1&titulo=' . rawurlencode($q)) ?>#nao-listado"><?= icon('plus') ?> Cadastrar livro não listado</a><?php endif; ?></div>
                <?php else: ?>
                    <ul class="adm-resultados adm-resultados--livros">
                        <?php foreach ($busca['livros'] as $l): ?>
                            <li id="livro-<?= (int)$l['id'] ?>">
                                <div>
                                    <strong><?= e($l['titulo']) ?></strong><?= (int)$l['alertas'] ? ' <span class="adm-status adm-tone--indigo">' . (int)$l['alertas'] . ' alerta(s)</span>' : '' ?>
                                    <small><?= e($l['autores'] ?: 'Autor não informado') ?><?= $l['isbn'] ? ' · ISBN ' . e($l['isbn']) : ($l['isbn_original'] ? ' · ISBN informado: ' . e($l['isbn_original']) : '') ?><?= $l['edicao'] ? ' · ' . e($l['edicao']) : '' ?></small>
                                    <small class="adm-table__muted">Planilha: <?= $l['quantidade_planilha'] !== null ? (int)$l['quantidade_planilha'] : '—' ?> (só referência) · Físicos cadastrados: <?= count($l['exemplares']) ?><?= $l['localizacao_antiga'] ? ' · Local antigo: ' . e($l['localizacao_antiga']) : '' ?></small>
                                    <?php if ($l['exemplares']): ?>
                                        <div class="adm-exemplares-chips">
                                            <?php foreach ($l['exemplares'] as $ex): ?>
                                                <?php if ($ex['conferido_em']): ?>
                                                    <span class="adm-chip adm-chip--ok" title="Já conferido neste inventário"><?= icon('check') ?> <?= e($ex['codigo_individual']) ?></span>
                                                <?php else: ?>
                                                    <a class="adm-chip" href="<?= e($base . '&codigo=' . rawurlencode($ex['codigo_individual'])) ?>"><?= e($ex['codigo_individual']) ?> · <?= e(Biblioteca\Services\ExemplarService::rotuloSituacao($ex['situacao'])) ?></a>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($l['registros']): ?>
                                        <div class="adm-exemplares-chips">
                                            <?php foreach ($l['registros'] as $reg): ?>
                                                <?php $rotReg = $reg['conferido_em'] ? 'Encontrado' : ($reg['nao_localizado_em'] ? 'Não localizado' : 'Pendente'); ?>
                                                <a class="adm-chip<?= $reg['conferido_em'] ? ' adm-chip--ok' : '' ?>" href="<?= e($base . '&item=' . (int)$reg['id']) ?>#registro"><?= icon('file-spreadsheet') ?> <?= $reg['linha'] ? 'Linha ' . (int)$reg['linha'] . ' da planilha' : 'Registro sem código' ?> · <?= e($rotReg) ?></a>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($podeEditarLivro): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&corrigir=' . (int)$l['id'] . '&q=' . rawurlencode($q)) ?>#corrigir-livro"><?= icon('pencil') ?> Corrigir dados</a><?php endif; ?>
                                <?php if ($podeCadastrar): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&livro=' . (int)$l['id'] . '&q=' . rawurlencode($q)) ?>#cadastro"><?= icon('plus') ?> Cadastrar exemplar</a><?php endif; ?>
                                <?php if (!$l['exemplares'] && $l['registros']): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '&item=' . (int)$l['registros'][0]['id']) ?>#registro"><?= icon('square-check') ?> Conferir</a><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($busca['mais']): ?><p class="adm-field__hint">Há mais resultados. Refine a pesquisa.</p><?php endif; ?>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <?php if ($livroCorrigir): ?>
            <section class="adm-card adm-panel" id="corrigir-livro" aria-labelledby="titulo-corrigir">
                <div class="adm-panel__header"><div>
                    <h2 id="titulo-corrigir"><?= icon('pencil') ?> Corrigir dados do livro</h2>
                    <p>Corrija título, autor, ISBN, editora, ano, categoria e os demais dados. Campos desconhecidos podem ficar vazios: o que faltar continua como alerta cadastral, sem impedir a conferência.</p>
                </div></div>
                <form method="post" action="<?= e($base) ?>#corrigir-livro" class="adm-form-section" data-loading-form>
                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="salvar_livro"><input type="hidden" name="livro_id" value="<?= (int)$livroCorrigir['id'] ?>"><input type="hidden" name="voltar" value="corrigir"><input type="hidden" name="q" value="<?= e($q) ?>">
                    <?php $lv = $livroCorrigir; $alertasLv = $alertasCorrigir; $editavelLv = true; include __DIR__ . '/includes/form-conferencia-livro.php'; $alertasLv = []; ?>
                    <div class="adm-form-actions adm-form-actions--static">
                        <button class="adm-button adm-button--primary" type="submit"><?= icon('save') ?> Salvar correções</button>
                        <a class="adm-button adm-button--ghost" href="<?= e($base . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>">Fechar</a>
                    </div>
                </form>
                <?php if ($itensCorrigir): ?>
                    <h3 class="adm-field__label">Exemplares e registros deste livro no inventário</h3>
                    <div class="adm-exemplares-chips">
                        <?php foreach ($itensCorrigir as $ic): ?>
                            <?php $rotIc = $ic['conferido_em'] ? 'Encontrado' : ($ic['nao_localizado_em'] ? 'Não localizado' : 'Pendente'); ?>
                            <?php if ($ic['exemplar_id'] !== null): ?>
                                <a class="adm-chip<?= $ic['conferido_em'] ? ' adm-chip--ok' : '' ?>" href="<?= e($base . '&codigo=' . rawurlencode((string)$ic['codigo_individual'])) ?>"><?= e($ic['codigo_individual']) ?> · <?= e($rotIc) ?></a>
                            <?php else: ?>
                                <a class="adm-chip<?= $ic['conferido_em'] ? ' adm-chip--ok' : '' ?>" href="<?= e($base . '&item=' . (int)$ic['id']) ?>#registro"><?= icon('file-spreadsheet') ?> <?= $ic['origem_linha'] ? 'Linha ' . (int)$ic['origem_linha'] . ' da planilha' : 'Registro sem código' ?> · <?= e($rotIc) ?></a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    <p class="adm-field__hint">Para registrar o código BDP e marcar como encontrado, abra o exemplar ou o registro pendente acima.</p>
                <?php endif; ?>
            </section>
        <?php elseif ($corrigirId && !$podeEditarLivro): ?>
            <?= admin_aviso('aviso', 'Seu perfil não pode corrigir dados de livros.', 'Peça a permissão "Editar livros" a quem administra o sistema.') ?>
        <?php endif; ?>

        <?php if ($itemRegistro && $livroLv): ?>
            <?php [$rotItem, $tomItem] = inventario_item_situacao($itemRegistro); ?>
            <section class="adm-card adm-panel" id="registro" aria-labelledby="titulo-registro">
                <div class="adm-panel__header"><div>
                    <h2 id="titulo-registro"><?= e($itemRegistro['titulo']) ?> <span class="adm-status adm-tone--<?= $tomItem ?>"><?= e($rotItem) ?></span></h2>
                    <p>Registro <?= $itemRegistro['origem_linha'] ? 'da linha ' . (int)$itemRegistro['origem_linha'] . ' da planilha' . ($itemRegistro['origem_arquivo'] ? ' “' . e($itemRegistro['origem_arquivo']) . '”' : '') : 'sem código BDP' ?>
                        <?= $itemRegistro['localizacao_antiga'] ? ' · local antigo: <strong>' . e($itemRegistro['localizacao_antiga']) . '</strong>' : '' ?>
                        <?= $itemRegistro['quantidade_informada'] !== null ? ' · quantidade na planilha: ' . (int)$itemRegistro['quantidade_informada'] . ' (só referência)' : '' ?></p>
                </div></div>
                <?php if ($itemRegistro['nao_localizado_em']): ?>
                    <?= admin_aviso('aviso', 'Marcado como não localizado em ' . admin_data_hora($itemRegistro['nao_localizado_em']) . ($itemRegistro['nao_localizado_por_nome'] ? ' por ' . $itemRegistro['nao_localizado_por_nome'] : '') . '.', ($itemRegistro['nao_localizado_obs'] ? $itemRegistro['nao_localizado_obs'] . ' · ' : '') . 'Achou o livro? Basta registrar o código e salvar: a marcação é desfeita.') ?>
                <?php endif; ?>
                <?php if ($itemRegistro['conferido_em']): ?>
                    <?= admin_aviso('sucesso', 'Já encontrado.', 'Este registro foi marcado como encontrado em ' . admin_data_hora($itemRegistro['conferido_em']) . '.') ?>
                <?php else: ?>
                <form method="post" action="<?= e($base) ?>" class="adm-form-section" data-loading-form>
                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="encontrar"><input type="hidden" name="item_id" value="<?= (int)$itemRegistro['id'] ?>">
                    <?php $lv = $livroLv; $editavelLv = $podeEditarLivro; include __DIR__ . '/includes/form-conferencia-livro.php'; ?>
                    <fieldset class="adm-fieldset adm-form-section">
                        <legend class="adm-field__label">Exemplar em mãos</legend>
                        <div class="adm-form-grid">
                            <label class="adm-field"><span class="adm-field__sublabel">Código BDP do carimbo</span>
                                <input class="adm-input adm-input--code" name="codigo_bdp" maxlength="30" placeholder="BDP-000123" autocapitalize="characters" spellcheck="false" autocomplete="off">
                                <span class="adm-field__hint">Digite o código carimbado no livro. Sem carimbo? Deixe vazio: o sistema gera o próximo código livre. Um código nunca se repete.</span></label>
                            <label class="adm-field"><span class="adm-field__sublabel">Estado de conservação</span><?= fisico_select_conservacao('estado_conservacao', 'bom') ?></label>
                            <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Localização (estante ou prateleira)</span><?= fisico_select_local('localizacao_id', $localSessao, 'Não informar agora', '', $locais) ?></label>
                            <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: capa solta"></label>
                        </div>
                    </fieldset>
                    <div class="adm-form-actions adm-form-actions--static">
                        <button class="adm-button adm-button--primary" type="submit"><?= icon('square-check') ?> Salvar e marcar como encontrado</button>
                        <?php if ($podeEditarLivro): ?><button class="adm-button adm-button--ghost" type="submit" name="acao" value="salvar_livro" formnovalidate><?= icon('save') ?> Salvar só as correções</button><input type="hidden" name="livro_id" value="<?= (int)$livroLv['id'] ?>"><input type="hidden" name="voltar" value="item"><?php endif; ?>
                    </div>
                </form>
                <?php endif; ?>
                <div class="adm-form-actions adm-form-actions--static adm-form-actions--secundarias">
                    <?php if (!$itemRegistro['conferido_em'] && !$itemRegistro['nao_localizado_em']): ?>
                        <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirm="Marcar este registro como não localizado? Você pode desfazer depois.">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="nao_localizado"><input type="hidden" name="item_id" value="<?= (int)$itemRegistro['id'] ?>">
                            <input class="adm-input adm-input--sm" name="observacao" maxlength="500" placeholder="Onde foi procurado (opcional)">
                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('book-x') ?> Marcar como não localizado</button>
                        </form>
                    <?php elseif ($itemRegistro['nao_localizado_em']): ?>
                        <form method="post" action="<?= e($base) ?>" class="adm-inline-form">
                            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="voltar_pendente"><input type="hidden" name="item_id" value="<?= (int)$itemRegistro['id'] ?>">
                            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('rotate-ccw') ?> Voltar para pendente</button>
                        </form>
                    <?php endif; ?>
                </div>
            </section>
        <?php elseif ($itemId && !$itemRegistro): ?>
            <?= admin_aviso('erro', 'Registro não encontrado neste inventário.', 'Pesquise o livro pelo título, autor ou ISBN.') ?>
        <?php endif; ?>

        <?php if ($mostrarNovo): ?>
            <?php $nv = $novoForm ?? ['titulo' => mb_substr(admin_param($_GET, 'titulo'), 0, 255)]; ?>
            <section class="adm-card adm-panel" id="nao-listado" aria-labelledby="titulo-nao-listado">
                <div class="adm-panel__header"><div><h2 id="titulo-nao-listado"><?= icon('book-plus') ?> Cadastrar livro não listado</h2>
                    <p>Para o livro que está em mãos e não aparece na pesquisa. Todos os campos podem ficar vazios: o que faltar vira alerta cadastral para completar depois. Ao salvar, o livro e o exemplar são cadastrados e já ficam como <strong>Encontrado</strong> neste inventário.</p></div></div>
                <form method="post" action="<?= e($base) ?>#nao-listado" class="adm-form-section" data-loading-form>
                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="nao_listado"><input type="hidden" name="token" value="<?= e($tokenNovo) ?>">
                    <?php if ($correspondencias): ?>
                        <div class="adm-correspondencias" role="group" aria-labelledby="titulo-corresp">
                            <p id="titulo-corresp"><?= icon('triangle-alert') ?> <strong>Encontramos <?= count($correspondencias) ?> livro(s) parecido(s) no sistema.</strong> Confira antes de cadastrar:</p>
                            <ul class="adm-resultados">
                                <?php foreach ($correspondencias as $c): ?>
                                    <li><label class="adm-check-confirm"><input type="radio" name="escolha" value="<?= (int)$c['id'] ?>" required>
                                        <span><strong><?= e($c['titulo']) ?></strong> · <?= e($c['autores'] ?: 'Autor não informado') ?><?= $c['isbn'] ? ' · ISBN ' . e($c['isbn']) : '' ?><?= $c['editora'] ? ' · ' . e($c['editora']) : '' ?>
                                        <small class="adm-block-muted"><?= e($c['motivo']) ?> · <?= (int)$c['exemplares'] ?> exemplar(es) · é outro exemplar desta obra (os dados acima não alteram este livro)</small></span></label></li>
                                <?php endforeach; ?>
                                <li><label class="adm-check-confirm"><input type="radio" name="escolha" value="novo" required> <span><strong>Nenhum destes: é um livro diferente.</strong> Cadastrar um livro novo com os dados abaixo.</span></label></li>
                            </ul>
                        </div>
                    <?php endif; ?>
                    <?php $lv = ['titulo' => $nv['titulo'] ?? '', 'subtitulo' => $nv['subtitulo'] ?? '', 'autores' => $nv['autores'] ?? '', 'isbn' => $nv['isbn'] ?? '', 'editora' => $nv['editora'] ?? '', 'ano_publicacao' => $nv['ano_publicacao'] ?? '', 'edicao' => $nv['edicao'] ?? '', 'idioma' => $nv['idioma'] ?? '', 'assunto' => $nv['assunto'] ?? '', 'categoria_id' => $nv['categoria_id'] ?? ''];
                          $alertasLv = []; $editavelLv = true; include __DIR__ . '/includes/form-conferencia-livro.php'; ?>
                    <fieldset class="adm-fieldset adm-form-section">
                        <legend class="adm-field__label">Exemplar em mãos</legend>
                        <div class="adm-form-grid">
                            <label class="adm-field"><span class="adm-field__sublabel">Código BDP do carimbo</span>
                                <input class="adm-input adm-input--code" name="codigo_bdp" maxlength="30" value="<?= e((string)($nv['codigo_bdp'] ?? '')) ?>" placeholder="BDP-000123" autocapitalize="characters" spellcheck="false" autocomplete="off">
                                <span class="adm-field__hint">Vazio = próximo código livre. O sistema recusa um código que já pertence a outro exemplar.</span></label>
                            <label class="adm-field"><span class="adm-field__sublabel">Estado de conservação</span><?= fisico_select_conservacao('estado_conservacao', (string)($nv['estado_conservacao'] ?? 'bom')) ?></label>
                            <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Localização (estante ou prateleira)</span><?= fisico_select_local('localizacao_id', isset($nv['localizacao_id']) && $nv['localizacao_id'] !== '' ? (int)$nv['localizacao_id'] : $localSessao, 'Não informar agora', '', $locais) ?></label>
                            <label class="adm-field adm-field--full"><span class="adm-field__sublabel">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" value="<?= e((string)($nv['observacao'] ?? '')) ?>"></label>
                        </div>
                    </fieldset>
                    <div class="adm-form-actions adm-form-actions--static">
                        <button class="adm-button adm-button--primary" type="submit"><?= icon('square-check') ?> <?= $correspondencias ? 'Confirmar e marcar como encontrado' : 'Salvar e marcar como encontrado' ?></button>
                        <a class="adm-button adm-button--ghost" href="<?= e($base) ?>">Cancelar</a>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <?php if ($livroCadastro): ?>
            <section class="adm-card adm-panel" id="cadastro" aria-labelledby="titulo-cadastro">
                <div class="adm-panel__header"><div><h2 id="titulo-cadastro">Cadastrar exemplar novo · <?= e($livroCadastro['titulo']) ?></h2>
                    <p>Use só para o livro que está em mãos e ainda não tem código. O exemplar é cadastrado e já fica conferido neste inventário. A quantidade da planilha não é usada.</p></div></div>
                <form method="post" action="<?= e($base) ?>" class="adm-form-section" data-loading-form>
                    <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="cadastrar"><input type="hidden" name="livro_id" value="<?= (int)$livroCadastro['id'] ?>">
                    <?php $fx += ['localizacao_id' => (string)($localSessao ?? '')]; $livroForm = $livroCadastro; include __DIR__ . '/includes/form-exemplar.php'; ?>
                    <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required> <span>Estou com o(s) exemplar(es) em mãos e quero gerar o(s) código(s) BDP.</span></label>
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('book-plus') ?> Cadastrar e conferir</button>
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

        <section class="adm-card adm-panel" aria-labelledby="titulo-recentes">
            <h2 id="titulo-recentes" class="adm-panel__title">Últimas conferências</h2>
            <?php if (!$recentes): ?>
                <p class="adm-field__hint">Nenhuma conferência registrada ainda.</p>
            <?php else: ?>
                <ol class="adm-recentes">
                    <?php foreach ($recentes as $r): ?>
                        <?php [$rot, $tom] = inventario_item_situacao($r); ?>
                        <li><a class="adm-code" href="<?= e($r['codigo_individual'] ? $base . '&codigo=' . rawurlencode($r['codigo_individual']) : $base . '&item=' . (int)$r['id']) ?>"><?= e($r['codigo_individual'] ?? 'Sem código') ?></a>
                            <span class="adm-status adm-tone--<?= $tom ?>"><?= e($rot) ?></span>
                            <small class="adm-block-muted"><?= e(mb_strimwidth($r['titulo'], 0, 60, '…')) ?> · <?= admin_data_hora($r['conferido_em']) ?></small></li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </aside>
</div>
<?php endif; ?>

<?php
admin_page_end(FISICO_JS);
