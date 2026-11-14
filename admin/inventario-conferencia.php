<?php
/**
 * Conferência do inventário em andamento: CONFERÊNCIA CONTÍNUA (migração 130).
 *
 * Fluxo, tudo na mesma página (com JavaScript as ações trocam só as regiões da página, sem
 * recarregar; sem JavaScript, cada ação volta para esta mesma página):
 *   1. Pesquisa por título, autor, ISBN ou código BDP (resultados compactos, enquanto digita,
 *      paginados de 15 em 15).
 *   2. A obra abre aqui mesmo, com o status "Dados verificados" ou "Dados não verificados".
 *   3. Obra não verificada: "Verificar e corrigir dados" → "Salvar e marcar dados como
 *      verificados" → o formulário do exemplar abre sozinho. Ou "Vincular a uma obra já
 *      verificada" (sugestões; nada é unido sem confirmação).
 *   4. "+ Registrar exemplar": "Gerar código BDP" (reserva no servidor, só deste formulário),
 *      escolha consciente da linha da planilha (ou "Exemplar adicional"), salvar como Encontrado.
 *   5. Depois de salvar: "Registrar outra cópia" (mesma obra, mesma localização e estado, código
 *      novo) ou "Próximo livro" (volta para a pesquisa, mantendo a estante atual).
 *
 * OBRA × EXEMPLAR: a obra (livros) tem os dados bibliográficos e a verificação, feita UMA vez.
 * Cada exemplar tem o seu código BDP e a sua situação no inventário. Linhas importadas sem
 * exemplar não contam como exemplares encontrados.
 *
 * "Estante atual" (coluna lateral): a localização em que a equipe está trabalhando. Vem
 * preenchida em cada registro; mostra encontrados, pendentes, não localizados e o progresso da
 * estante, com "Finalizar estante" (só os itens escolhidos viram "Não localizado") e "Reabrir".
 *
 * Endereços: &q= pesquisa (&pagina=), &obra=ID ficha da obra (&painel=verificar|registrar,
 * &copia=1 mantém local/estado do último registro, &criados=ID exemplar recém-registrado),
 * &codigo= exemplar lido, &novo=1 livro não listado. Requisições com o cabeçalho
 * "X-Conferencia: 1" recebem só as regiões da página (data-regiao), usadas pelo conferencia.js.
 */
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/acervo-fisico.php';

use Biblioteca\Repositories\EmprestimoRepository;
use Biblioteca\Repositories\InventarioRepository;
use Biblioteca\Services\AcervoException;
use Biblioteca\Services\CodigoBdpService;
use Biblioteca\Services\ConferenciaDuplicadaException;
use Biblioteca\Services\ExemplarService;
use Biblioteca\Services\InventarioService;
use Biblioteca\Services\ObraVerificacaoService;
use Biblioteca\Services\RegistroRepetidoException;
use Biblioteca\Services\VinculoObraService;
use Biblioteca\Support\CodigoBdp;

$usuario = fisico_iniciar('inventario.conferir');
$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$base = admin_url('inventario-conferencia') . '?id=' . $id;
$fragmento = ($_SERVER['HTTP_X_CONFERENCIA'] ?? '') === '1';
$podeGerenciar = auth_pode('inventario.gerenciar');
$podeCadastrar = auth_pode('exemplares.gerenciar');
$podeEditarLivro = auth_pode('livros.editar');
$sessao = ($_SESSION['inventario_conferencia'][$id] ?? []) + ['local' => null, 'rapido' => false, 'ultimo' => null];
$erro = null;
$novoForm = null;          // valores do formulário "livro não listado" após um erro ou na etapa de correspondências
$correspondencias = null;  // livros parecidos encontrados antes de cadastrar
$registrarForm = null;     // valores do painel "+ Registrar exemplar" após um erro ou "Gerar código BDP" sem JavaScript
$verificarForm = null;     // valores do painel "Verificar e corrigir dados" após um erro
$painel = admin_param($_GET, 'painel'); // painel aberto ao carregar: verificar | registrar | finalizar

/** Token de uso único do formulário "livro não listado" (protege contra clique duplo e reenvio). */
function conferencia_token_novo(int $inventarioId): string
{
    $t = bin2hex(random_bytes(16));
    $_SESSION['inv_nao_listado'][$t] = $inventarioId;
    $_SESSION['inv_nao_listado'] = array_slice($_SESSION['inv_nao_listado'], -20, null, true);
    return $t;
}

/** Dados do exemplar físico enviados pelo formulário (código BDP, estado, localização, observação, situação, linha da planilha). */
function conferencia_fisico(array $post, ?int $localPadrao): array
{
    return [
        'codigo' => mb_substr(admin_param($post, 'codigo_bdp'), 0, 30),
        'estado_conservacao' => admin_param($post, 'estado_conservacao', 'bom'),
        'localizacao_id' => admin_param($post, 'localizacao_id') !== '' ? admin_param($post, 'localizacao_id') : $localPadrao,
        'observacao' => mb_substr(admin_param($post, 'observacao'), 0, 500),
        'situacao_inventario' => admin_param($post, 'situacao_inventario', 'encontrado'),
        'vinculo' => isset($post['vinculo']) ? admin_param($post, 'vinculo') : (isset($post['tem_linhas']) ? '' : null),
        'processo_bdp' => admin_param($post, 'processo_bdp'),
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

/** Guarda as escolhas do último exemplar registrado ("Registrar outra cópia" reaproveita local e estado). */
function conferencia_lembrar_ultimo(int $id, array $f): void
{
    $_SESSION['inventario_conferencia'][$id]['ultimo'] = ['localizacao_id' => $f['localizacao_id'] !== null ? (string)$f['localizacao_id'] : '', 'estado_conservacao' => $f['estado_conservacao']];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    admin_exigir_csrf();
    $acao = admin_param($_POST, 'acao');
    try {
        switch ($acao) {
            case 'local':
                // "Estante atual": fica como padrão em cada registro até ser trocada.
                $local = (int)admin_param($_POST, 'local_conferencia') ?: null;
                if ($local !== null && !db_query('SELECT 1 FROM localizacoes WHERE id = ?', [$local])->fetchColumn()) {
                    throw new AcervoException('Localização não encontrada.');
                }
                $_SESSION['inventario_conferencia'][$id] = ['local' => $local, 'rapido' => admin_param($_POST, 'rapido') === '1'] + $sessao;
                admin_flash('sucesso', $local ? 'Estante atual escolhida.' : 'Nenhuma estante selecionada.', $local ? 'Ela vem preenchida em cada exemplar registrado e em cada conferência.' : 'Escolha a localização em cada registro.');
                admin_redirecionar($base . (admin_param($_POST, 'voltar') !== '' ? '&' . http_build_query(['obra' => (int)admin_param($_POST, 'voltar')]) : ''));
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
                admin_redirecionar($base . '&conferido=1');
            case 'verificar_obra':
                // "Verificar e corrigir dados": dados da OBRA. Só "Salvar e marcar dados como verificados" muda o status.
                fisico_exigir('livros.editar');
                $livroAlvo = (int)admin_param($_POST, 'livro_id');
                $marcar = admin_param($_POST, 'marcar_verificado') === '1';
                $verificarForm = $_POST;
                $cad = conferencia_cadastro();
                $r = $cad->atualizarLivro($livroAlvo, $cad->dados($_POST), $usuario['id'], admin_param($_POST, 'confirmar_alertas') === '1', $marcar, $id);
                $alertasTxt = $r['alertas_abertos'] ? $r['alertas_abertos'] . ' alerta(s) cadastral(is) continuam abertos (não impedem a verificação).' : '';
                if ($marcar) {
                    // Conferência contínua: o registro do exemplar abre sozinho.
                    admin_flash('sucesso', 'Dados verificados.', ($r['alteracoes'] ? 'Correções salvas. ' : '') . 'Agora registre o exemplar que está em mãos. ' . $alertasTxt);
                    admin_redirecionar($base . '&obra=' . $livroAlvo . ($podeCadastrar ? '&painel=registrar' : '') . '#exemplares');
                }
                if ($r['alteracoes']) {
                    admin_flash($r['verificacao'] === 'verificado' ? 'sucesso' : 'aviso', 'Alterações salvas.', $r['verificacao'] === 'verificado'
                        ? 'A obra continua com os dados verificados. ' . $alertasTxt
                        : 'A obra está com “Dados não verificados”. Quando terminar de conferir, use “Salvar e marcar dados como verificados”. ' . $alertasTxt);
                } else {
                    admin_flash('aviso', 'Nenhuma informação alterada.', $alertasTxt);
                }
                admin_redirecionar($base . '&obra=' . $livroAlvo . '#obra');
            case 'vincular_obra':
                // "Vincular a uma obra já verificada": só com confirmação; as linhas escolhidas passam para a obra verificada.
                fisico_exigir('livros.editar');
                $origem = (int)admin_param($_POST, 'livro_id');
                $destino = (int)admin_param($_POST, 'destino_id');
                $sel = $_POST['registros'] ?? [];
                $r = vinculo_obra_servico()->vincular($origem, $destino, is_array($sel) ? $sel : [], $usuario['id'], admin_param($_POST, 'confirmar') === '1', $id, admin_param($_POST, 'observacao'));
                admin_flash('sucesso', $r['movidas'] . ' linha(s) da planilha vinculada(s) à obra verificada.', 'Os dados originais da planilha foram preservados e o vínculo ficou no histórico.'
                    . ($r['origem_arquivada'] ? ' O cadastro repetido ficou sem linhas e foi arquivado (não excluído).' : '') . ' Agora registre o exemplar físico.');
                admin_redirecionar($base . '&obra=' . $destino . ($podeCadastrar ? '&painel=registrar' : '') . '#exemplares');
            case 'desfazer_vinculo_obra':
                fisico_exigir('livros.editar');
                $volta = vinculo_obra_servico()->desfazer((int)admin_param($_POST, 'registro_id'), $usuario['id'], admin_param($_POST, 'motivo'), $id);
                admin_flash('sucesso', 'Vinculação desfeita.', 'A linha da planilha voltou para a obra original. Tudo ficou registrado no histórico.');
                admin_redirecionar($base . '&obra=' . $volta . '#registros');
            case 'desfazer_vinculo_linha':
                fisico_exigir('inventario.gerenciar');
                inventario_servico()->desfazerVinculoLinha($id, (int)admin_param($_POST, 'registro_id'), $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Vínculo com a linha da planilha desfeito.', 'O exemplar continua registrado, como exemplar adicional. A linha voltou para Pendente.');
                admin_redirecionar($base . '&obra=' . (int)admin_param($_POST, 'livro_id') . '#registros');
            case 'gerar_codigo':
                // "Gerar código BDP" sem JavaScript: reserva no servidor (deste formulário) e volta com o código.
                fisico_exigir('exemplares.gerenciar');
                $processo = CodigoBdpService::processoValido(admin_param($_POST, 'processo_bdp')) ?? CodigoBdpService::novoProcesso();
                $r = codigo_bdp_servico()->reservar($usuario['id'], (int)admin_param($_POST, 'livro_id') ?: null, $processo);
                $valores = array_merge($_POST, ['codigo_bdp' => $r['codigo'], 'codigo_gerado' => '1', 'processo_bdp' => $processo]);
                if (admin_param($_POST, 'origem_form') === 'nao_listado') {
                    $novoForm = $valores;
                } else {
                    $registrarForm = $valores;
                    $painel = 'registrar';
                }
                break;
            case 'cancelar_registro':
                // Cancelar depois de gerar o código: a reserva é cancelada (o número não volta e não é reaproveitado).
                $codigo = admin_param($_POST, 'codigo_bdp');
                $cancelou = $codigo !== '' && codigo_bdp_servico()->cancelar($codigo, $usuario['id'], admin_param($_POST, 'processo_bdp'));
                admin_flash('aviso', 'Registro cancelado. Nada foi gravado.', $cancelou ? 'O código ' . CodigoBdp::normalizar($codigo) . ' foi cancelado e não será usado em nenhum livro. Se já foi escrito num carimbo, risque-o.' : '');
                $livroAlvo = (int)admin_param($_POST, 'livro_id');
                admin_redirecionar($base . ($livroAlvo ? '&obra=' . $livroAlvo : '') . '#exemplares');
            case 'registrar_exemplar':
                // "+ Registrar exemplar": exemplar físico de uma obra verificada, com código BDP definitivo ao salvar.
                fisico_exigir('exemplares.gerenciar');
                $livroAlvo = (int)admin_param($_POST, 'livro_id');
                $registrarForm = $_POST;
                $painel = 'registrar';
                $f = conferencia_fisico($_POST, $sessao['local']);
                $c = inventario_servico()->registrarExemplar($id, $livroAlvo, [
                    'codigo' => $f['codigo'], 'estado_conservacao' => $f['estado_conservacao'], 'localizacao_id' => $f['localizacao_id'],
                    'observacoes' => $f['observacao'], 'situacao_inventario' => $f['situacao_inventario'], 'vinculo' => $f['vinculo'], 'processo_bdp' => $f['processo_bdp'],
                    'item_id' => admin_param($_POST, 'item_id'), // endereço/formulário antigo (antes da escolha por linha)
                ], $usuario['id']);
                conferencia_lembrar_ultimo($id, $f);
                admin_flash('sucesso', 'Exemplar ' . $c['codigo'] . ' registrado como ' . InventarioService::SITUACOES_REGISTRO[$c['situacao']] . '.',
                    'Confira se o carimbo do livro mostra exatamente este código.' . ($c['registro_id'] ? ' A linha da planilha escolhida ficou ligada a este exemplar.' : ''));
                admin_redirecionar($base . '&obra=' . $livroAlvo . '&criados=' . (int)$c['id'] . '#registrado');
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
            case 'finalizar_estante':
                $local = (int)admin_param($_POST, 'localizacao_id');
                $sel = $_POST['itens'] ?? [];
                $sel = is_array($sel) ? $sel : [];
                if ($sel && admin_param($_POST, 'confirmar') !== '1') {
                    throw new AcervoException('Marque a confirmação: os exemplares escolhidos foram procurados e não estão nesta estante.');
                }
                $r = inventario_servico()->finalizarEstante($id, $local, $sel, admin_param($_POST, 'observacao'), $usuario['id']);
                admin_flash('sucesso', 'Estante finalizada.', fisico_numero($r['nao_localizados']) . ' exemplar(es) confirmado(s) como não localizado(s).'
                    . ($r['pendentes'] ? ' ' . fisico_numero($r['pendentes']) . ' continuam pendentes (não foram marcados).' : '') . ' Você pode reabrir a estante se precisar corrigir.');
                admin_redirecionar($base);
            case 'reabrir_estante':
                $n = inventario_servico()->reabrirEstante($id, (int)admin_param($_POST, 'localizacao_id'), admin_param($_POST, 'voltar_pendentes') === '1', $usuario['id']);
                admin_flash('sucesso', 'Estante reaberta.', $n ? fisico_numero($n) . ' exemplar(es) voltaram para Pendente.' : 'Os resultados já registrados foram mantidos.');
                admin_redirecionar($base);
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
                $fisico = conferencia_fisico($_POST, $sessao['local']);
                $res = $cad->cadastrarNaoListado($id, $dadosNovo, $fisico, $usuario['id'], null, $marcar);
                unset($_SESSION['inv_nao_listado'][$token]);
                $_SESSION['inv_nao_listado_feito'][$token] = ['codigo' => $res['codigo'], 'livro_id' => $res['livro_id']];
                $_SESSION['inv_nao_listado_feito'] = array_slice($_SESSION['inv_nao_listado_feito'], -20, null, true);
                $alertasTxt = $res['alertas'] ? ' ' . $res['alertas'] . ' alerta(s) cadastral(is) ficaram registrados para completar depois.' : '';
                if ($res['exemplar_id']) {
                    conferencia_lembrar_ultimo($id, $fisico);
                    admin_flash('sucesso', 'Obra cadastrada com dados verificados e exemplar ' . $res['codigo'] . ' registrado.', 'Confira se o carimbo do livro mostra este código.' . $alertasTxt);
                    admin_redirecionar($base . '&obra=' . $res['livro_id'] . '&criados=' . (int)$res['exemplar_id'] . '#registrado');
                }
                admin_flash('sucesso', 'Obra cadastrada com “Dados não verificados”.', 'Nenhum exemplar foi registrado. Quando conferir os dados, use “Verificar e corrigir dados”; o registro do exemplar abre em seguida.' . $alertasTxt);
                admin_redirecionar($base . '&obra=' . $res['livro_id'] . '#obra');
            case 'corrigir':
                fisico_exigir('inventario.gerenciar');
                $item = inventario_servico()->corrigir((int)admin_param($_POST, 'item_id'), [
                    'estado_conservacao' => admin_param($_POST, 'estado_conservacao'),
                    'localizacao_id' => admin_param($_POST, 'localizacao_id'),
                    'observacao' => admin_param($_POST, 'observacao'),
                ], $usuario['id'], admin_param($_POST, 'motivo'));
                admin_flash('sucesso', 'Conferência de ' . $item['codigo_individual'] . ' corrigida.', 'A correção ficou registrada no histórico, com o motivo.');
                admin_redirecionar($base . '&codigo=' . rawurlencode((string)$item['codigo_individual']));
            default:
                throw new AcervoException('Ação desconhecida.');
        }
    } catch (ConferenciaDuplicadaException $e) {
        admin_flash('aviso', 'Já estava marcado como encontrado: nada foi gravado de novo.', $e->getMessage());
        $cod = (string)($e->item['codigo_individual'] ?? '');
        admin_redirecionar($base . ($cod !== '' ? '&codigo=' . rawurlencode($cod) : ''));
    } catch (RegistroRepetidoException $e) {
        // Clique duplo / reenvio do mesmo formulário: mostra o exemplar já registrado, sem gravar de novo.
        admin_flash('aviso', 'Este exemplar já foi registrado: nada foi gravado de novo.', $e->getMessage());
        admin_redirecionar($base . '&obra=' . (int)admin_param($_POST, 'livro_id') . '&criados=' . $e->exemplarId . '#registrado');
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
    $paginaBusca = max(1, (int)admin_param($_GET, 'pagina', '1'));
    $exemplar = $codigoLido !== '' ? exemplares_repo()->porCodigo($codigoLido) : null;
    $itemAnterior = $exemplar ? inventario_repo()->itemPorExemplar($id, (int)$exemplar['id']) : null;
    $obraExemplar = $exemplar ? inventario_repo()->livroConferencia((int)$exemplar['livro_id']) : null;
    $busca = $q !== '' ? inventario_repo()->pesquisarLivros($id, $q, 15, $paginaBusca) : null;

    // Ficha da obra (&obra=ID, ou o livro do formulário que voltou com erro).
    $obraId = (int)($_GET['obra'] ?? 0);
    if (in_array(admin_param($_POST, 'acao'), ['verificar_obra', 'registrar_exemplar', 'gerar_codigo', 'vincular_obra'], true) && admin_param($_POST, 'origem_form') !== 'nao_listado') {
        $obraId = (int)admin_param($_POST, 'livro_id') ?: $obraId;
    }
    $obra = $obraId ? inventario_repo()->livroConferencia($obraId) : null;
    $obraAlertas = $obra ? inventario_repo()->alertasLivro($obraId) : [];
    $obraExemplares = $obra ? inventario_repo()->exemplaresDaObra($id, $obraId) : [];
    $obraLinhas = $obra ? inventario_repo()->registrosDaObra($id, $obraId) : [];
    $obraHistorico = $obra ? obra_verificacao_servico()->historico($obraId) : [];
    $obraVinculos = $obra ? vinculo_obra_servico()->historico($obraId, 20) : [];
    $obraVerificada = $obra && $obra['dados_verificados_em'] !== null;
    $obraLinhasLivres = array_values(array_filter($obraLinhas, fn ($r) => $r['exemplar_id'] === null));
    $vq = mb_substr(admin_param($_GET, 'vq'), 0, 100);
    $sugestoesVinculo = $obra && !$obraVerificada && $obraLinhasLivres && $podeEditarLivro ? vinculo_obra_servico()->sugestoes($obraId, $vq !== '' ? $vq : null) : [];
    if ($verificarForm !== null && $erro !== null) {
        $painel = 'verificar';
    }
    if (admin_param($_POST, 'acao') === 'registrar_exemplar' && $erro === null) {
        $registrarForm = null;
    }

    $mostrarNovo = $podeCadastrar && ($novoForm !== null || admin_param($_GET, 'novo') === '1');
    $tokenNovo = $mostrarNovo ? ($novoForm !== null && isset($_SESSION['inv_nao_listado'][admin_param($novoForm, 'token')]) ? admin_param($novoForm, 'token') : conferencia_token_novo($id)) : '';
    $categoriasLista = db_query('SELECT id, nome FROM categorias WHERE arquivado_em IS NULL ORDER BY nome')->fetchAll();
    $indicadores = inventario_repo()->indicadores($id);
    $recentes = inventario_repo()->recentes($id, 8);
    $locais = localizacoes_servico()->ativas();
    $criadosIds = array_filter(array_map('intval', explode(',', admin_param($_GET, 'criados'))));
    $criados = $criadosIds ? exemplares_repo()->paraEtiquetas($criadosIds) : [];
    $localSessao = $sessao['local'];
    $estante = $localSessao ? inventario_repo()->resumoEstante($id, $localSessao) : null;
    $estanteNome = null;
    foreach ($locais as $lc) {
        if ((int)$lc['id'] === (int)$localSessao) {
            $estanteNome = fisico_local($lc);
        }
    }
    $estantePendentes = $estante && $painel === 'finalizar' ? inventario_repo()->pendentesEstante($id, $localSessao) : null;
    $estanteNaoLocalizados = $estante && $estante['status'] === 'finalizada' && $painel === 'reabrir' ? inventario_repo()->naoLocalizadosEstante($id, $localSessao) : null;
} catch (PDOException $e) {
    http_response_code(500);
    admin_pagina_erro('Não foi possível abrir a conferência', fisico_erro_tecnico($e, 'conferência do inventário'));
    exit;
}
$emAndamento = $inv['status'] === 'em_andamento';
$det = admin_url('inventario-detalhes') . '?id=' . $id . '&filtro=';
$SEM_TITULO = InventarioRepository::SEM_TITULO;

// =====================================================================
// Regiões da página (trocadas pelo conferencia.js sem recarregar)
// =====================================================================

// ---------- Indicadores ----------
ob_start();
$ind = $indicadores;
?>
<section class="adm-card adm-panel adm-indicadores-inv" aria-labelledby="titulo-indicadores">
    <div class="adm-indicadores-inv__topo">
        <h2 id="titulo-indicadores" class="adm-panel__title"><?= icon('chart-column') ?> Indicadores</h2>
        <span class="adm-table__muted">Obras, exemplares e linhas da planilha são contados separadamente.</span>
    </div>
    <div class="adm-indicadores-inv__progresso">
        <p><strong><?= $ind['pct_exemplares'] ?>%</strong> dos exemplares deste inventário já têm resultado
            <span class="adm-table__muted">(<?= fisico_numero($ind['ex_com_resultado']) ?> de <?= fisico_numero($ind['ex_total']) ?> exemplares físicos: encontrados ou não localizados)</span></p>
        <div class="adm-progress adm-progress--lg" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $ind['pct_exemplares'] ?>" aria-label="Exemplares com resultado"><div class="adm-progress__bar adm-progress__bar--ok" style="width: <?= $ind['pct_exemplares'] ?>%"></div></div>
        <p class="adm-table__muted"><?= icon('circle-check') ?> Obras com dados verificados: <strong><?= fisico_numero($ind['obras_verificadas']) ?> de <?= fisico_numero($ind['obras']) ?></strong> (<?= $ind['pct_obras'] ?>% das obras do acervo)</p>
    </div>
    <h3 class="adm-indicadores-inv__grupo">Acervo</h3>
    <div class="adm-stats adm-stats--indicadores">
        <?= fisico_stat('Obras cadastradas', $ind['obras'], 'Títulos/edições (não arquivados)', 'library', 'blue') ?>
        <?= fisico_stat('Dados verificados', $ind['obras_verificadas'], 'Obras conferidas pela equipe', 'circle-check', 'green') ?>
        <?= fisico_stat('Dados não verificados', $ind['obras_nao_verificadas'], 'Obras ainda a conferir', 'circle-help', 'amber') ?>
        <?= fisico_stat('Exemplares físicos', $ind['exemplares'], 'Livros com código BDP (não baixados)', 'book-copy', 'indigo') ?>
        <?= fisico_stat('Linhas sem vínculo físico', $ind['linhas_sem_vinculo'], 'Linhas da planilha ainda sem exemplar', 'file-spreadsheet', 'muted', $det . 'sem_codigo') ?>
        <?php if ($ind['sem_codigo'] > 0): ?><?= fisico_stat('Exemplares sem código BDP', $ind['sem_codigo'], 'Dados antigos fora do padrão BDP-000000', 'tag', 'red') ?><?php endif; ?>
    </div>
    <h3 class="adm-indicadores-inv__grupo">Este inventário · exemplares físicos</h3>
    <div class="adm-stats adm-stats--indicadores">
        <?= fisico_stat('Encontrados', $ind['ex_encontrados'], 'Exemplares conferidos na estante', 'square-check', 'green', $det . 'encontrados') ?>
        <?= fisico_stat('Pendentes', $ind['ex_pendentes'], 'Exemplares ainda não conferidos', 'hourglass', 'amber', $det . 'a_conferir') ?>
        <?= fisico_stat('Não localizados', $ind['ex_nao_localizados'], 'Procurados e não achados', 'book-x', 'red', $det . 'nao_localizados') ?>
        <?php if ($ind['ex_emprestados'] > 0): ?><?= fisico_stat('Emprestados', $ind['ex_emprestados'], 'Fora da estante por empréstimo', 'arrow-right-left', 'indigo', $det . 'em_circulacao') ?><?php endif; ?>
    </div>
    <p class="adm-field__hint"><?= icon('file-spreadsheet') ?> Linhas da planilha neste inventário: <strong><?= fisico_numero($ind['linhas_pendentes']) ?></strong> pendente(s) e <?= fisico_numero($ind['linhas_nao_localizadas']) ?> não localizada(s). Elas <strong>não contam como exemplares</strong> até alguém registrar o livro físico.</p>
</section>
<?php
$htmlIndicadores = ob_get_clean();

// ---------- Estante atual ----------
ob_start();
?>
<section class="adm-card adm-panel adm-form-section adm-estante" aria-labelledby="titulo-estante">
    <h2 id="titulo-estante" class="adm-panel__title"><?= icon('map-pin') ?> Estante atual</h2>
    <form method="post" action="<?= e($base) ?>" class="adm-estante__form" data-estante-form>
        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="local">
        <?php if ($obra): ?><input type="hidden" name="voltar" value="<?= (int)$obra['id'] ?>"><?php endif; ?>
        <label class="adm-field"><span class="adm-field__sublabel">Onde você está trabalhando agora</span>
            <?= fisico_select_local('local_conferencia', $localSessao, 'Nenhuma (escolher em cada registro)', 'local-conferencia', $locais) ?></label>
        <label class="adm-check-confirm"><input type="checkbox" name="rapido" value="1"<?= $sessao['rapido'] ? ' checked' : '' ?>> <span>Modo rápido: livro com etiqueta é conferido assim que o código é digitado</span></label>
        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('save') ?> Aplicar</button>
    </form>
    <?php if (!$locais): ?><p class="adm-field__hint">Nenhuma localização cadastrada. <a class="adm-link" href="<?= e(admin_url('localizacoes')) ?>">Cadastrar localizações</a></p><?php endif; ?>
    <?php if ($estante): ?>
        <p class="adm-estante__nome"><strong><?= e((string)$estanteNome) ?></strong>
            <?= $estante['status'] === 'finalizada' ? '<span class="adm-status adm-tone--indigo">Finalizada' . ($estante['finalizada_em'] ? ' em ' . e(admin_data_hora($estante['finalizada_em'])) : '') . '</span>' : '<span class="adm-status adm-tone--green">Em conferência</span>' ?></p>
        <div class="adm-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $estante['percentual'] ?>" aria-label="Progresso da estante"><div class="adm-progress__bar adm-progress__bar--ok" style="width: <?= $estante['percentual'] ?>%"></div></div>
        <p class="adm-table__muted"><?= $estante['percentual'] ?>% conferido · <?= fisico_numero($estante['com_resultado']) ?> de <?= fisico_numero($estante['esperados']) ?> exemplar(es) cadastrados nesta estante</p>
        <dl class="adm-estante__numeros">
            <div><dt>Encontrados aqui</dt><dd><?= fisico_numero($estante['encontrados_aqui']) ?></dd></div>
            <div><dt>Pendentes</dt><dd><?= fisico_numero($estante['pendentes']) ?></dd></div>
            <div><dt>Não localizados</dt><dd><?= fisico_numero($estante['nao_localizados']) ?></dd></div>
            <?php if ($estante['emprestados']): ?><div><dt>Emprestados</dt><dd><?= fisico_numero($estante['emprestados']) ?></dd></div><?php endif; ?>
        </dl>
        <?php if ($estante['fora_do_lugar'] > 0): ?><p class="adm-field__hint"><?= fisico_numero($estante['fora_do_lugar']) ?> encontrado(s) aqui estava(m) cadastrado(s) em outro lugar.</p><?php endif; ?>
        <p class="adm-field__hint">Contam só os exemplares cadastrados nesta estante. Linhas da planilha sem localização não entram aqui.</p>
        <?php if ($emAndamento): ?>
            <div class="adm-actions-inline">
                <?php if ($estante['status'] !== 'finalizada'): ?>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&painel=finalizar') ?>" data-pjax><?= icon('flag') ?> Finalizar estante</a>
                <?php else: ?>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&painel=reabrir') ?>" data-pjax><?= icon('rotate-ccw') ?> Reabrir estante</a>
                    <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&painel=finalizar') ?>" data-pjax><?= icon('flag') ?> Rever pendentes</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php else: ?>
        <p class="adm-field__hint">Escolha a estante ou prateleira em que está trabalhando (ex.: Estante A — Prateleira 1). Ela vem preenchida em cada exemplar registrado, inclusive em “Registrar outra cópia”.</p>
    <?php endif; ?>
</section>

<?php if ($estante && $estantePendentes !== null && $emAndamento): ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-finalizar" aria-labelledby="painel-finalizar-titulo" open data-painel-auto>
        <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
            <header class="adm-modal__header">
                <div><h2 id="painel-finalizar-titulo"><?= icon('flag') ?> Finalizar estante</h2><p><?= e((string)$estanteNome) ?> · <?= count($estantePendentes) ?> exemplar(es) ainda pendente(s)</p></div>
                <a class="adm-modal__close" href="<?= e($base) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a>
            </header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="finalizar_estante"><input type="hidden" name="localizacao_id" value="<?= (int)$localSessao ?>">
                <?php if (!$estantePendentes): ?>
                    <div class="adm-empty adm-empty--left"><?= icon('square-check') ?><strong>Nenhum exemplar pendente nesta estante.</strong><span>Todos os exemplares cadastrados aqui já têm resultado. Finalize para registrar a conclusão.</span></div>
                <?php else: ?>
                    <p class="adm-field__hint">Estes exemplares estavam cadastrados nesta estante e ainda não foram encontrados. <strong>Marque só os que você procurou e confirmou que não estão aqui.</strong> Os não marcados continuam Pendentes. Emprestados não aparecem.</p>
                    <label class="adm-check-confirm"><input type="checkbox" data-marcar-todos="itens[]"> <span>Selecionar todos da lista</span></label>
                    <ul class="adm-finalizar-lista">
                        <?php foreach ($estantePendentes as $p): ?>
                            <li><label><input type="checkbox" name="itens[]" value="<?= (int)$p['id'] ?>"> <span class="adm-code"><?= e((string)$p['codigo_individual']) ?></span> <?= e(mb_strimwidth($p['titulo'], 0, 80, '…')) ?> <small class="adm-table__muted"><?= e($p['autores'] ?: '') ?></small></label></li>
                        <?php endforeach; ?>
                    </ul>
                    <label class="adm-field"><span class="adm-field__sublabel">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: procurado também no carrinho de devolução"></label>
                    <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1"> <span>Confirmo que os exemplares marcados foram procurados e <strong>não estão nesta estante</strong>. A situação deles no acervo não muda (não viram extraviados) e pode ser desfeita.</span></label>
                <?php endif; ?>
            </div>
            <footer class="adm-modal__footer">
                <a class="adm-button adm-button--ghost" href="<?= e($base) ?>" data-painel-fechar>Voltar</a>
                <button class="adm-button adm-button--primary" type="submit"><?= icon('flag') ?> Finalizar estante</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($estante && $estanteNaoLocalizados !== null && $emAndamento): ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-reabrir" aria-labelledby="painel-reabrir-titulo" open data-painel-auto>
        <form class="adm-modal__box" method="post" action="<?= e($base) ?>">
            <header class="adm-modal__header">
                <div><h2 id="painel-reabrir-titulo"><?= icon('rotate-ccw') ?> Reabrir estante</h2><p><?= e((string)$estanteNome) ?></p></div>
                <a class="adm-modal__close" href="<?= e($base) ?>" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a>
            </header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="reabrir_estante"><input type="hidden" name="localizacao_id" value="<?= (int)$localSessao ?>">
                <p class="adm-field__hint">A estante volta para “Em conferência”. Exemplares encontrados continuam encontrados.</p>
                <?php if ($estanteNaoLocalizados): ?>
                    <p><strong><?= count($estanteNaoLocalizados) ?> exemplar(es) desta estante estão como “Não localizado”:</strong></p>
                    <ul class="adm-finalizar-lista"><?php foreach ($estanteNaoLocalizados as $p): ?><li><span class="adm-code"><?= e((string)$p['codigo_individual']) ?></span> <?= e(mb_strimwidth($p['titulo'], 0, 80, '…')) ?></li><?php endforeach; ?></ul>
                    <label class="adm-check-confirm"><input type="checkbox" name="voltar_pendentes" value="1"> <span>Voltar todos eles para <strong>Pendente</strong> (para procurar de novo)</span></label>
                <?php endif; ?>
            </div>
            <footer class="adm-modal__footer">
                <a class="adm-button adm-button--ghost" href="<?= e($base) ?>" data-painel-fechar>Voltar</a>
                <button class="adm-button adm-button--primary" type="submit"><?= icon('rotate-ccw') ?> Reabrir estante</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>
<?php
$htmlEstante = ob_get_clean();

// ---------- Últimas conferências ----------
ob_start();
?>
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
<?php
$htmlRecentes = ob_get_clean();

// ---------- Área principal (resultado da pesquisa, obra, exemplar lido, livro não listado) ----------
ob_start();
?>
<div class="adm-conferencia__mensagens" role="status" aria-live="polite">
    <?= admin_flash_html() ?>
    <?php if ($erro && !in_array($painel, ['verificar', 'registrar'], true)): ?><?= admin_aviso('erro', 'Nada foi gravado.', $erro) ?><?php endif; ?>
</div>
<?php if (!$emAndamento): ?>
    <?= admin_aviso('info', 'Este inventário não está em andamento.', $inv['status'] === 'pausado' ? 'Ele está pausado. Retome-o na página do inventário para continuar a conferência.' : 'A conferência só é possível com o inventário em andamento.') ?>
<?php else: ?>

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
                    <strong><?= e($obraExemplar['titulo'] ?? $SEM_TITULO) ?></strong>
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
                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirmar="Desfazer o “Encontrado” deste exemplar? Ele volta para Pendente.">
                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="voltar_pendente"><input type="hidden" name="item_id" value="<?= (int)$itemAnterior['id'] ?>">
                        <input class="adm-input adm-input--sm" name="motivo" maxlength="500" required placeholder="Motivo (ex.: código digitado errado)" aria-label="Motivo para desfazer">
                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('rotate-ccw') ?> Desfazer “Encontrado”</button>
                    </form>
                <?php else: ?>
                    <p class="adm-field__hint">Se a conferência anterior estiver errada, peça a correção a quem gerencia o inventário.</p>
                <?php endif; ?>
                <p><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base) ?>" data-proximo data-atalho="proximo"><?= icon('arrow-right') ?> Próximo livro</a></p>
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
                <form method="post" action="<?= e($base) ?>" class="adm-form-section" data-sem-enter>
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
                        <button class="adm-button adm-button--primary" type="submit" data-foco><?= icon('square-check') ?> Salvar e marcar como encontrado</button>
                        <a class="adm-button adm-button--ghost" href="<?= e($base) ?>" data-proximo>Cancelar</a>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($busca !== null && !$obra): ?>
    <section class="adm-card adm-panel adm-busca-conf" aria-labelledby="titulo-busca">
        <div class="adm-panel__header"><div><h2 id="titulo-busca">Obras para “<?= e($q) ?>”<?= $paginaBusca > 1 ? ' · página ' . $paginaBusca : '' ?></h2>
            <p>Escolha a obra que está em mãos. <kbd>↓</kbd> navega pelos resultados.</p></div></div>
        <?php if (!$busca['livros']): ?>
            <div class="adm-empty adm-empty--left"><?= icon('book-x') ?><strong>Nenhum livro encontrado.</strong><span>Tente outra palavra do título, o sobrenome do autor ou o ISBN. Se o livro está em mãos e não consta no sistema, cadastre-o aqui mesmo.</span>
                <?php if ($podeCadastrar): ?><a class="adm-button adm-button--primary adm-button--sm" href="<?= e($base . '&novo=1&titulo=' . rawurlencode($q)) ?>#nao-listado"><?= icon('plus') ?> Cadastrar livro não listado</a><?php endif; ?></div>
        <?php else: ?>
            <ul class="adm-resultados-compactos" data-resultados>
                <?php foreach ($busca['livros'] as $l): $verif = $l['dados_verificados_em'] !== null; $abrir = $base . '&obra=' . (int)$l['id']; ?>
                    <li id="livro-<?= (int)$l['id'] ?>" class="<?= $verif ? 'is-verificada' : 'is-nao-verificada' ?>">
                        <a class="adm-resultado__principal" href="<?= e($abrir) ?>#obra">
                            <strong><?= e($l['titulo']) ?></strong>
                            <small><?= e($l['autores'] ?: 'Autor não informado') ?><?= $l['isbn'] ? ' · ISBN ' . e($l['isbn']) : ($l['isbn_original'] ? ' · ISBN informado: ' . e($l['isbn_original']) : '') ?><?= $l['editora'] ? ' · ' . e($l['editora']) : '' ?><?= $l['ano_publicacao'] ? ' · ' . (int)$l['ano_publicacao'] : '' ?><?= $l['edicao'] ? ' · ' . e($l['edicao']) : '' ?></small>
                        </a>
                        <span class="adm-resultado__info">
                            <?= obra_badge_verificacao($l['dados_verificados_em']) ?>
                            <span class="adm-chip" title="Exemplares físicos com código BDP"><?= icon('book-copy') ?> <?= count($l['exemplares']) ?> exemplar(es)</span>
                            <?php if ((int)$l['linhas_sem_vinculo'] > 0): ?><span class="adm-chip" title="Linhas da planilha ainda sem exemplar"><?= icon('file-spreadsheet') ?> <?= (int)$l['linhas_sem_vinculo'] ?> linha(s) sem exemplar</span><?php endif; ?>
                        </span>
                        <span class="adm-resultado__acoes">
                            <?php if (!$verif && $podeEditarLivro): ?>
                                <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($abrir . '&painel=verificar') ?>#obra"><?= icon('pencil') ?> Verificar dados</a>
                            <?php elseif ($verif && $podeCadastrar): ?>
                                <a class="adm-button adm-button--primary adm-button--sm" href="<?= e($abrir . '&painel=registrar') ?>#exemplares"><?= icon('plus') ?> Registrar exemplar</a>
                            <?php endif; ?>
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($abrir) ?>#obra">Abrir</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <nav class="adm-busca-conf__paginas" aria-label="Páginas dos resultados">
                <?php if ($paginaBusca > 1): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&q=' . rawurlencode($q) . '&pagina=' . ($paginaBusca - 1)) ?>"><?= icon('chevron-left') ?> Anteriores</a><?php endif; ?>
                <?php if ($busca['mais']): ?><a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&q=' . rawurlencode($q) . '&pagina=' . ($paginaBusca + 1)) ?>">Mais resultados <?= icon('chevron-right') ?></a><?php endif; ?>
                <?php if ($podeCadastrar): ?><a class="adm-link" href="<?= e($base . '&novo=1&titulo=' . rawurlencode($q)) ?>#nao-listado">Não está na lista? Cadastrar livro não listado</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($obraId && !$obra): ?>
    <?= admin_aviso('erro', 'Obra não encontrada.', 'Ela pode ter sido arquivada. Pesquise de novo pelo título, autor ou ISBN.') ?>
<?php elseif ($obra): ?>
    <?php
    $tituloObra = $obra['titulo'] ?? $SEM_TITULO;
    $isbnObra = $obra['isbn'] ?: ($obra['isbn_original'] ? $obra['isbn_original'] . ' (como foi informado)' : null);
    $ativos = array_filter($obraExemplares, fn ($x) => $x['situacao'] !== 'baixado');
    $encontradosObra = count(array_filter($ativos, fn ($x) => $x['conferido_em'] !== null));
    ?>
    <?php if ($criados): ?>
        <section class="adm-card adm-panel adm-criados adm-registrado" id="registrado" aria-labelledby="titulo-registrado" tabindex="-1" data-foco>
            <h2 id="titulo-registrado"><?= icon('square-check') ?> Exemplar registrado. Confira no carimbo do livro:</h2>
            <ul class="adm-code-list">
                <?php foreach ($criados as $c): ?><li><a class="adm-code adm-code--grande" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$c['id']) ?>"><?= e($c['codigo_individual']) ?></a></li><?php endforeach; ?>
            </ul>
            <p class="adm-table__muted"><?= e($tituloObra) ?> · <?= count($ativos) ?> exemplar(es) desta obra</p>
            <div class="adm-actions-inline adm-registrado__acoes">
                <?php if ($podeCadastrar && $obraVerificada): ?><a class="adm-button adm-button--primary" href="<?= e($base . '&obra=' . (int)$obra['id'] . '&painel=registrar&copia=1') ?>#exemplares" data-atalho="copia"><?= icon('plus') ?> Registrar outra cópia <kbd>C</kbd></a><?php endif; ?>
                <a class="adm-button adm-button--primary" href="<?= e($base) ?>" data-proximo data-atalho="proximo"><?= icon('arrow-right') ?> Próximo livro <kbd>N</kbd></a>
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
                : 'Os dados desta obra ainda não foram conferidos. Confira com o livro em mãos (uma vez só) antes de registrar exemplares.' ?></p>
        </div>
        <?php if ($podeEditarLivro): ?>
            <a class="adm-button <?= $obraVerificada ? 'adm-button--ghost' : 'adm-button--primary' ?>" href="<?= e($base . '&obra=' . (int)$obra['id'] . '&painel=verificar') ?>#obra" data-painel-abrir="painel-verificar" data-atalho="verificar"<?= !$obraVerificada && !$criados && $painel === '' ? ' data-foco' : '' ?>><?= icon('pencil') ?> <?= $obraVerificada ? 'Corrigir dados da obra' : 'Verificar e corrigir dados' ?></a>
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
        <?php if ($obraHistorico || $obraVinculos): ?>
            <details class="adm-acao adm-obra__historico">
                <summary><?= icon('history') ?> Histórico (<?= count($obraHistorico) + count($obraVinculos) ?>)</summary>
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
                    <?php foreach ($obraVinculos as $h): ?>
                        <li><strong><?= e(VinculoObraService::descrever($h)) ?></strong>
                            <small class="adm-block-muted"><?= admin_data_hora($h['criado_em']) ?><?= $h['administrador_nome'] ? ' · ' . e($h['administrador_nome']) : '' ?><?= $h['observacao'] ? ' · ' . e($h['observacao']) : '' ?></small></li>
                    <?php endforeach; ?>
                </ol>
            </details>
        <?php endif; ?>
    </section>

    <?php if ($sugestoesVinculo || ($vq !== '' && !$obraVerificada && $obraLinhasLivres)): ?>
        <section class="adm-card adm-panel adm-vincular" id="vincular" aria-labelledby="titulo-vincular">
            <div class="adm-panel__header"><div>
                <h2 id="titulo-vincular"><?= icon('link') ?> Vincular a uma obra já verificada</h2>
                <p>Estas obras <strong>já têm os dados verificados</strong> e parecem ser a mesma. Se o livro em mãos for <strong>a mesma obra e a mesma edição</strong>, vincule as linhas da planilha a ela e registre o exemplar sem verificar tudo de novo. <strong>Na dúvida, não vincule</strong>: edições diferentes ficam separadas.</p>
            </div></div>
            <?php if (!$sugestoesVinculo): ?><p class="adm-field__hint">Nenhuma obra verificada encontrada para “<?= e($vq) ?>”.</p><?php endif; ?>
            <ul class="adm-sugestoes-vinculo">
                <?php foreach ($sugestoesVinculo as $s): ?>
                    <li>
                        <div>
                            <strong><?= e($s['titulo']) ?></strong> <?= obra_badge_verificacao($s['dados_verificados_em']) ?>
                            <small class="adm-block-muted"><?= e($s['autores'] ?: 'Autor não informado') ?><?= $s['isbn'] ? ' · ISBN ' . e($s['isbn']) : '' ?><?= $s['editora'] ? ' · ' . e($s['editora']) : '' ?><?= $s['ano_publicacao'] ? ' · ' . (int)$s['ano_publicacao'] : '' ?><?= $s['edicao'] ? ' · ' . e($s['edicao']) : '' ?> · <?= (int)$s['exemplares'] ?> exemplar(es)</small>
                            <span class="adm-vincular__motivos"><?php foreach ($s['motivos'] as $m): ?><span class="adm-status adm-tone--green"><?= e($m) ?></span> <?php endforeach; ?>
                                <?php foreach ($s['diferencas'] as $d): ?><span class="adm-status adm-tone--red"><?= icon('triangle-alert') ?> <?= e($d) ?></span> <?php endforeach; ?></span>
                            <?php if ($s['diferencas']): ?><small class="adm-block-muted"><strong>Atenção:</strong> pode ser outra edição. Confira editora, ano e ISBN no livro em mãos.</small><?php endif; ?>
                        </div>
                        <details class="adm-acao">
                            <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('link') ?> É esta obra</summary>
                            <form method="post" action="<?= e($base . '&obra=' . (int)$obra['id']) ?>" class="adm-acao__form">
                                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="vincular_obra"><input type="hidden" name="livro_id" value="<?= (int)$obra['id'] ?>"><input type="hidden" name="destino_id" value="<?= (int)$s['id'] ?>">
                                <fieldset class="adm-escolha"><legend class="adm-field__sublabel">Linhas da planilha que passam para a obra verificada</legend>
                                    <?php foreach ($obraLinhasLivres as $reg): ?>
                                        <label class="adm-escolha__opcao"><input type="checkbox" name="registros[]" value="<?= (int)$reg['id'] ?>" checked> <span>Linha <?= (int)$reg['linha'] ?> <small class="adm-table__muted">“<?= e($reg['arquivo']) ?>”<?= $reg['titulo_original'] ? ' · ' . e(mb_strimwidth($reg['titulo_original'], 0, 60, '…')) : '' ?></small></span></label>
                                    <?php endforeach; ?>
                                </fieldset>
                                <label class="adm-field"><span class="adm-field__sublabel">Observação <span class="adm-optional">(opcional)</span></span><input class="adm-input" name="observacao" maxlength="500" placeholder="Ex.: mesma edição, conferido pela ficha catalográfica"></label>
                                <label class="adm-check-confirm"><input type="checkbox" name="confirmar" value="1" required> <span>Confirmo que o livro em mãos é a <strong>mesma obra e a mesma edição</strong>. Os dados originais da planilha ficam preservados e a vinculação pode ser desfeita.</span></label>
                                <button class="adm-button adm-button--primary adm-button--sm" type="submit"><?= icon('link') ?> Vincular e registrar exemplar</button>
                            </form>
                        </details>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form method="get" action="<?= e($base) ?>" class="adm-inline-form">
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="obra" value="<?= (int)$obra['id'] ?>">
                <input class="adm-input adm-input--sm" name="vq" value="<?= e($vq) ?>" maxlength="100" placeholder="Procurar outra obra verificada pelo título" aria-label="Procurar outra obra verificada pelo título">
                <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('search') ?> Procurar</button>
            </form>
        </section>
    <?php elseif (!$obraVerificada && $obraLinhasLivres && $podeEditarLivro): ?>
        <form method="get" action="<?= e($base) ?>" class="adm-inline-form adm-vincular__procurar">
            <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="obra" value="<?= (int)$obra['id'] ?>">
            <span class="adm-field__hint"><?= icon('link') ?> Nenhuma obra verificada parecida. Já verificou esta obra em outro cadastro?</span>
            <input class="adm-input adm-input--sm" name="vq" maxlength="100" placeholder="Título da obra verificada" aria-label="Procurar obra verificada pelo título">
            <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('search') ?> Procurar</button>
        </form>
    <?php endif; ?>

    <section class="adm-card adm-panel adm-obra-exemplares" id="exemplares" aria-labelledby="titulo-exemplares">
        <div class="adm-panel__header"><div>
            <h2 id="titulo-exemplares"><?= icon('book-copy') ?> Exemplares físicos <span class="adm-table__muted">(<?= count($ativos) ?>, <?= $encontradosObra ?> encontrado(s) neste inventário)</span></h2>
            <p>Cada exemplar tem o seu código BDP e a sua situação no inventário. Vários exemplares usam o mesmo cadastro da obra.</p>
        </div>
        <?php if ($podeCadastrar): ?>
            <?php if ($obraVerificada): ?>
                <a class="adm-button adm-button--primary" href="<?= e($base . '&obra=' . (int)$obra['id'] . '&painel=registrar') ?>#exemplares" data-painel-abrir="painel-registrar" data-atalho="registrar"><?= icon('plus') ?> Registrar exemplar</a>
            <?php else: ?>
                <span class="adm-button adm-button--ghost is-disabled" aria-disabled="true" title="Verifique os dados da obra primeiro"><?= icon('lock') ?> Registrar exemplar</span>
            <?php endif; ?>
        <?php endif; ?>
        </div>
        <?php if (!$obraVerificada && $podeCadastrar): ?>
            <?= admin_aviso('info', 'Primeiro, verifique os dados da obra.', 'Clique em “Verificar e corrigir dados” e use “Salvar e marcar dados como verificados”: o registro do exemplar abre em seguida. Se a obra já foi verificada em outro cadastro, use “Vincular a uma obra já verificada”.') ?>
        <?php endif; ?>
        <?php if (!$obraExemplares): ?>
            <div class="adm-empty adm-empty--left"><?= icon('book-copy') ?><strong>Nenhum exemplar físico registrado ainda.</strong><span>As linhas da planilha abaixo não são exemplares: elas só viram exemplar quando alguém registra o livro físico com um código BDP.</span></div>
        <?php else: ?>
            <ul class="adm-exemplares-obra">
                <?php foreach ($obraExemplares as $ex): [$rotEx, $tomEx] = inventario_exemplar_status($ex); ?>
                    <li class="<?= in_array((int)$ex['id'], $criadosIds, true) ? 'is-novo' : '' ?>">
                        <span class="adm-code"><?= e($ex['codigo_individual']) ?></span>
                        <span class="adm-status adm-tone--<?= $tomEx ?>"><?= e($rotEx) ?></span>
                        <span class="adm-exemplares-obra__info">
                            <?= e(fisico_local($ex)) ?> · <?= e(ACERVO_CONSERVACAO[$ex['estado_conservacao']] ?? $ex['estado_conservacao']) ?> · <?= e(ExemplarService::rotuloSituacao($ex['emprestimo_aberto'] ? 'emprestado' : $ex['situacao'])) ?>
                            <?= $ex['origem_linha'] ? ' · linha ' . (int)$ex['origem_linha'] . ' da planilha' : '' ?><?= $ex['item_id'] === null && $ex['situacao'] !== 'baixado' ? ' · fora da lista deste inventário' : '' ?>
                        </span>
                        <span class="adm-actions-inline">
                            <?php if ($ex['conferido_em'] === null && $ex['situacao'] !== 'baixado'): ?>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&codigo=' . rawurlencode($ex['codigo_individual'])) ?>"><?= icon('square-check') ?> Marcar como encontrado</a>
                            <?php elseif ($ex['conferido_em'] !== null): ?>
                                <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e($base . '&codigo=' . rawurlencode($ex['codigo_individual'])) ?>"><?= icon('pencil') ?> Corrigir</a>
                            <?php endif; ?>
                            <a class="adm-button adm-button--ghost adm-button--sm" href="<?= e(admin_url('detalhes-exemplar') . '?id=' . (int)$ex['id']) ?>"><?= icon('eye') ?> Detalhes</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>

    <?php if ($obraLinhas): ?>
        <section class="adm-card adm-panel" id="registros" aria-labelledby="titulo-registros">
            <div class="adm-panel__header"><div>
                <h2 id="titulo-registros"><?= icon('file-spreadsheet') ?> Linhas da planilha desta obra (<?= count($obraLinhas) ?>, <?= count($obraLinhasLivres) ?> sem exemplar)</h2>
                <p>Cada linha importada é conferida individualmente. Linha sem exemplar <strong>não conta como exemplar encontrado</strong>: ela só fica ligada a um livro físico quando você a escolhe ao registrar o exemplar.</p>
            </div></div>
            <ul class="adm-registros-obra">
                <?php foreach ($obraLinhas as $reg): ?>
                    <li id="registro-<?= (int)($reg['item_id'] ?? 0) ?>">
                        <span><?= icon('file-spreadsheet') ?> <strong>Linha <?= (int)$reg['linha'] ?></strong> <small class="adm-table__muted">“<?= e($reg['arquivo']) ?>”</small></span>
                        <?php if ($reg['exemplar_id'] !== null): ?>
                            <span class="adm-status adm-tone--green">Ligada ao <?= e((string)$reg['codigo_individual']) ?></span>
                        <?php elseif ($reg['item_id'] === null): ?>
                            <span class="adm-status adm-tone--muted">Sem exemplar · fora deste inventário</span>
                        <?php else: [$rotReg, $tomReg] = inventario_item_situacao($reg + ['emprestimo_aberto' => null]); ?>
                            <span class="adm-status adm-tone--<?= $tomReg ?>">Sem exemplar · <?= e($rotReg) ?></span>
                        <?php endif; ?>
                        <small class="adm-table__muted"><?= e(implode(' · ', array_filter([
                            $reg['titulo_original'] ? 'Título na planilha: ' . mb_strimwidth($reg['titulo_original'], 0, 70, '…') : null,
                            $reg['autor_original'] ? 'Autor: ' . mb_strimwidth($reg['autor_original'], 0, 50, '…') : null,
                            $reg['localizacao_antiga'] ? 'Local antigo: ' . $reg['localizacao_antiga'] : null,
                            $reg['quantidade_informada'] !== null ? 'Qtd. na planilha: ' . (int)$reg['quantidade_informada'] . ' (só referência)' : null,
                            $reg['vinculado_em'] ? 'Ligada em ' . admin_data_hora($reg['vinculado_em']) . ($reg['vinculado_por_nome'] ? ' por ' . $reg['vinculado_por_nome'] : '') : null,
                            $reg['livro_original_id'] ? 'Vinculada a partir de “' . $reg['livro_original_titulo'] . '”' : null,
                            $reg['nao_localizado_em'] ? 'Não localizada em ' . admin_data_hora($reg['nao_localizado_em']) . ($reg['nao_localizado_obs'] ? ' (' . $reg['nao_localizado_obs'] . ')' : '') : null,
                        ]))) ?></small>
                        <span class="adm-actions-inline">
                            <?php if ($reg['exemplar_id'] === null && $reg['item_id'] !== null && $reg['conferido_em'] === null): ?>
                                <?php if (!$reg['nao_localizado_em']): ?>
                                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form" data-confirmar="Marcar esta linha da planilha como não localizada? Você pode desfazer depois.">
                                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="nao_localizado"><input type="hidden" name="item_id" value="<?= (int)$reg['item_id'] ?>"><input type="hidden" name="livro_id" value="<?= (int)$obra['id'] ?>">
                                        <input class="adm-input adm-input--sm" name="observacao" maxlength="500" placeholder="Onde foi procurado (opcional)" aria-label="Onde foi procurado (opcional)">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('book-x') ?> Não localizado</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="<?= e($base) ?>" class="adm-inline-form">
                                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="voltar_pendente"><input type="hidden" name="item_id" value="<?= (int)$reg['item_id'] ?>">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit"><?= icon('rotate-ccw') ?> Voltar para pendente</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($reg['exemplar_id'] !== null && $podeGerenciar): ?>
                                <details class="adm-acao">
                                    <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('undo-2') ?> Ligada por engano?</summary>
                                    <form method="post" action="<?= e($base) ?>" class="adm-acao__form">
                                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="desfazer_vinculo_linha"><input type="hidden" name="registro_id" value="<?= (int)$reg['id'] ?>"><input type="hidden" name="livro_id" value="<?= (int)$obra['id'] ?>">
                                        <input class="adm-input adm-input--sm" name="motivo" maxlength="500" required placeholder="Motivo (ex.: era a linha 12, não a 8)" aria-label="Motivo">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit">Desfazer vínculo com o exemplar</button>
                                        <small class="adm-block-muted">O exemplar continua registrado (como exemplar adicional) e a linha volta para Pendente.</small>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if ($reg['livro_original_id'] && $reg['exemplar_id'] === null && $podeEditarLivro): ?>
                                <details class="adm-acao">
                                    <summary class="adm-button adm-button--ghost adm-button--sm"><?= icon('undo-2') ?> Desfazer vinculação</summary>
                                    <form method="post" action="<?= e($base) ?>" class="adm-acao__form">
                                        <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="desfazer_vinculo_obra"><input type="hidden" name="registro_id" value="<?= (int)$reg['id'] ?>">
                                        <input class="adm-input adm-input--sm" name="motivo" maxlength="500" required placeholder="Motivo (ex.: outra edição)" aria-label="Motivo">
                                        <button class="adm-button adm-button--ghost adm-button--sm" type="submit">Devolver para a obra original</button>
                                    </form>
                                </details>
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
            <p>Para o livro que está em mãos e não aparece na pesquisa. 1) Dados da <strong>obra</strong> (todos opcionais: não preencha o que não souber). 2) Confirme os dados. 3) <strong>Gerar código BDP</strong> e escreva no carimbo. 4) Salve: o exemplar fica Encontrado neste inventário.</p></div></div>
        <form method="post" action="<?= e($base) ?>#nao-listado" class="adm-form-section" data-sem-enter>
            <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="nao_listado"><input type="hidden" name="token" value="<?= e($tokenNovo) ?>">
            <?php if ($correspondencias): ?>
                <div class="adm-correspondencias" role="group" aria-labelledby="titulo-corresp">
                    <p id="titulo-corresp"><?= icon('triangle-alert') ?> <strong>Encontramos <?= count($correspondencias) ?> obra(s) parecida(s) no sistema.</strong> Confira antes de cadastrar. Nada é unido automaticamente: edições diferentes podem ter o mesmo título.</p>
                    <ul class="adm-resultados">
                        <?php foreach ($correspondencias as $c): ?>
                            <li><span><strong><?= e($c['titulo']) ?></strong> · <?= e($c['autores'] ?: 'Autor não informado') ?><?= $c['isbn'] ? ' · ISBN ' . e($c['isbn']) : '' ?><?= $c['editora'] ? ' · ' . e($c['editora']) : '' ?><?= $c['ano_publicacao'] ? ' · ' . (int)$c['ano_publicacao'] : '' ?><?= $c['edicao'] ? ' · ' . e($c['edicao']) : '' ?>
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
            <p class="adm-field__hint">Ao clicar no botão principal, você confirma que conferiu os dados da obra com o livro em mãos: ela já fica com <strong>Dados verificados</strong> e o exemplar é registrado como Encontrado. Se ainda não conferiu, cadastre só a obra; o exemplar fica para depois.</p>
            <div class="adm-form-actions adm-form-actions--static">
                <?php if ($podeEditarLivro): ?><button class="adm-button adm-button--primary" type="submit" name="marcar_verificado" value="1"><?= icon('square-check') ?> Salvar, marcar dados como verificados e registrar exemplar</button><?php endif; ?>
                <button class="adm-button adm-button--ghost" type="submit" name="marcar_verificado" value="0" formnovalidate><?= icon('save') ?> Cadastrar só a obra (dados não verificados)</button>
                <a class="adm-button adm-button--ghost" href="<?= e($base) ?>" data-proximo>Cancelar</a>
            </div>
        </form>
    </section>
<?php endif; ?>

<?php if (!$codigoLido && $busca === null && !$obra && !$mostrarNovo): ?>
    <section class="adm-card adm-panel adm-conferencia__inicio" aria-label="Como conferir">
        <ol class="adm-passos-conf">
            <li><strong>Pesquise</strong> o livro que está em mãos (título, autor, ISBN ou código BDP).</li>
            <li>Obra com <strong>dados não verificados</strong>? Confira e clique em <strong>Salvar e marcar dados como verificados</strong> (uma vez por obra).</li>
            <li><strong>Gerar código BDP</strong>, escreva no carimbo, escolha a linha da planilha e salve.</li>
            <li><strong>Registrar outra cópia</strong> ou <strong>Próximo livro</strong>.</li>
        </ol>
        <?php if ($podeCadastrar): ?><p class="adm-field__hint">O livro não está no sistema? <a class="adm-link" href="<?= e($base . '&novo=1') ?>#nao-listado">+ Cadastrar livro não listado</a></p><?php endif; ?>
    </section>
<?php endif; ?>

<?php if ($obra && $podeEditarLivro): ?>
    <?php
    $vf = $verificarForm;
    $lv = $vf !== null
        ? ['titulo' => $vf['titulo'] ?? '', 'subtitulo' => $vf['subtitulo'] ?? '', 'autores' => $vf['autores'] ?? '', 'isbn' => $vf['isbn'] ?? '', 'editora' => $vf['editora'] ?? '', 'ano_publicacao' => $vf['ano_publicacao'] ?? '', 'edicao' => $vf['edicao'] ?? '', 'idioma' => $vf['idioma'] ?? '', 'assunto' => $vf['assunto'] ?? '', 'categoria_id' => $vf['categoria_id'] ?? '']
        : $obra;
    $alertasLv = $obraAlertas; $editavelLv = true;
    ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-verificar" aria-labelledby="painel-verificar-titulo"<?= $painel === 'verificar' ? ' open data-painel-auto' : '' ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base . '&obra=' . (int)$obra['id']) ?>#obra" data-sem-enter>
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
                <?php else: ?>
                    <p class="adm-field__hint"><?= icon('info') ?> Depois de “Salvar e marcar dados como verificados”, o registro do exemplar abre sozinho.</p>
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
    $ultimo = admin_param($_GET, 'copia') === '1' ? ($sessao['ultimo'] ?? null) : null;
    $rx = ($registrarForm ?? []) + ($ultimo ?? []) + ['localizacao_id' => (string)($localSessao ?? '')];
    $rxLivroId = (int)$obra['id']; $rxRegistros = $obraLinhas; $rxOrigem = 'registrar';
    ?>
    <dialog class="adm-modal adm-modal--wide adm-painel-lateral" id="painel-registrar" aria-labelledby="painel-registrar-titulo"<?= $painel === 'registrar' ? ' open data-painel-auto' : '' ?>>
        <form class="adm-modal__box" method="post" action="<?= e($base . '&obra=' . (int)$obra['id']) ?>#exemplares" data-sem-enter>
            <header class="adm-modal__header">
                <div><h2 id="painel-registrar-titulo"><?= icon('plus') ?> Registrar exemplar<?= $ultimo ? ' · outra cópia' : '' ?></h2>
                    <p><?= e($tituloObra) ?> · <?= count($ativos) ?> exemplar(es) já registrado(s)</p></div>
                <a class="adm-modal__close" href="<?= e($base . '&obra=' . (int)$obra['id']) ?>#exemplares" data-painel-fechar aria-label="Fechar"><?= icon('x') ?></a>
            </header>
            <div class="adm-modal__body">
                <?= csrf_campo() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="registrar_exemplar"><input type="hidden" name="livro_id" value="<?= (int)$obra['id'] ?>">
                <?php if ($obraLinhas): ?><input type="hidden" name="tem_linhas" value="1"><?php endif; ?>
                <?php if ($erro && $painel === 'registrar'): ?><?= admin_aviso('erro', 'Nada foi gravado.', $erro) ?><?php endif; ?>
                <p class="adm-field__hint">Os dados bibliográficos já estão verificados: aqui você registra só o livro físico que está em mãos. Uma cópia por vez, cada uma com o seu código BDP.<?= $ultimo ? ' Localização e estado vieram do exemplar anterior.' : '' ?></p>
                <?php include __DIR__ . '/includes/form-registrar-exemplar.php'; ?>
            </div>
            <footer class="adm-modal__footer">
                <button class="adm-button adm-button--ghost" type="submit" name="acao" value="cancelar_registro" formnovalidate title="Cancela também o código gerado neste formulário">Cancelar</button>
                <button class="adm-button adm-button--primary" type="submit" data-salvar><?= icon('save') ?> Salvar exemplar</button>
            </footer>
        </form>
    </dialog>
<?php endif; ?>
<?php endif; ?>
<?php
$htmlArea = ob_get_clean();

// Requisição da conferência contínua: só as regiões.
if ($fragmento) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="pt-BR"><body>';
    echo '<div data-regiao="indicadores">' . $htmlIndicadores . '</div>';
    echo '<div data-regiao="area">' . $htmlArea . '</div>';
    echo '<div data-regiao="estante">' . $htmlEstante . '</div>';
    echo '<div data-regiao="recentes">' . $htmlRecentes . '</div>';
    echo '</body></html>';
    exit;
}

admin_page_start('inventario', 'Conferência: ' . $inv['titulo'], 'Inventário', 'Conferência digital do acervo físico', FISICO_CSS);
?>

<?= acervo_breadcrumb([['Administração', admin_url('dashboard')], ['Inventário', admin_url('inventario')], [$inv['titulo'], admin_url('inventario-detalhes') . '?id=' . $id], ['Conferência']]) ?>

<section class="adm-page-head">
    <div>
        <h1>Conferência <?= fisico_badge_inventario($inv['status']) ?></h1>
        <p><?= e($inv['titulo']) ?> · pesquise, verifique a obra uma vez e registre cada exemplar com o seu código BDP, sem sair desta página</p>
    </div>
    <div class="adm-page-head__actions">
        <?php if ($podeCadastrar && $emAndamento): ?><a class="adm-button adm-button--primary" href="<?= e($base . '&novo=1') ?>#nao-listado" data-pjax><?= icon('plus') ?> Cadastrar livro não listado</a><?php endif; ?>
        <a class="adm-button adm-button--ghost" href="<?= e(admin_url('inventario-detalhes') . '?id=' . $id) ?>"><?= icon('list-checks') ?> Ver itens e pendências</a>
    </div>
</section>

<div data-regiao="indicadores"><?= $htmlIndicadores ?></div>

<div class="adm-conferencia" data-conferencia data-base="<?= e($base) ?>">
    <div class="adm-conferencia__main">
        <?php if ($emAndamento): ?>
        <section class="adm-card adm-panel adm-leitura" aria-labelledby="titulo-leitura">
            <form method="post" action="<?= e($base) ?>" class="adm-leitura__form" autocomplete="off" data-pesquisa>
                <?= csrf_campo() ?>
                <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="acao" value="ler">
                <label for="leitura" id="titulo-leitura" class="adm-leitura__label"><?= icon('keyboard') ?> Pesquisar livro: título, autor, ISBN ou código BDP</label>
                <div class="adm-leitura__linha">
                    <input class="adm-input adm-leitura__campo" type="search" id="leitura" name="leitura" maxlength="100" value="<?= e($q) ?>" placeholder="Ex.: Dom Casmurro, Machado, 978… ou BDP-000001"<?= $obra || $mostrarNovo || $codigoLido ? '' : ' autofocus' ?> data-campo-codigo autocapitalize="off" autocomplete="off" spellcheck="false" aria-describedby="leitura-dica"
                        data-busca-viva="<?= e($base) ?>" aria-controls="conferencia-area">
                    <button class="adm-button adm-button--primary" type="submit"><?= icon('search') ?> Pesquisar</button>
                </div>
                <p class="adm-field__hint" id="leitura-dica">
                    Os resultados aparecem enquanto você digita. Livro com etiqueta? Digite o código BDP e pressione Enter.
                    <?= $sessao['rapido'] ? '<strong>Modo rápido ligado:</strong> o código digitado é conferido na hora, com a estante atual.' : '' ?>
                    Atalhos: <kbd>/</kbd> pesquisa · <kbd>N</kbd> próximo livro · <kbd>C</kbd> outra cópia · <kbd>Ctrl</kbd>+<kbd>Enter</kbd> salva o formulário aberto.
                </p>
            </form>
        </section>
        <?php endif; ?>
        <div id="conferencia-area" class="adm-conferencia__area" data-regiao="area" aria-live="polite"><?= $htmlArea ?></div>
    </div>

    <aside class="adm-conferencia__side">
        <div data-regiao="estante"><?= $htmlEstante ?></div>
        <div data-regiao="recentes"><?= $htmlRecentes ?></div>
    </aside>
</div>

<?php
admin_page_end(array_merge(FISICO_JS, ['assets/js/conferencia.js?v=20261114']));
