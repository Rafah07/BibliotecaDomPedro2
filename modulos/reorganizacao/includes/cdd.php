<?php
/** Integração com a classificação CDD permanente (admin/includes/cdd.php), usada pelo cadastro do Grupo 2. */
require_once __DIR__ . '/../../../admin/includes/cdd.php';

use Biblioteca\Services\AcervoException;

/**
 * CDD do registro gravada na classificação permanente DENTRO da transação do cadastro (mesmas regras
 * da conferência do Inventário: aprovada para quem aprova CDD, sugestão para os demais). Se falhar,
 * o cadastro inteiro é desfeito: nenhum exemplar fica liberado ao Grupo 3 sem a classificação.
 */
function reorg_registrar_cdd(array $usuario): callable
{
    return function (int $livroId, string $cdd, string $fonte) use ($usuario): void {
        try {
            $v = cdd_validar($cdd);
            if (!$v['valida']) {
                throw new AcervoException('CDD ' . $cdd . ': ' . $v['erro']);
            }
            cdd_itens(true);
            $item = cdd_item($livroId);
            if (!$item) {
                throw new AcervoException('Obra não encontrada para registrar a CDD.');
            }
            $permissoes = AUTH_PERMISSOES[$usuario['perfil']] ?? [];
            $acao = in_array('cdd.aprovar', $permissoes, true) ? 'aprovar' : (in_array('cdd.sugerir', $permissoes, true) ? 'sugerir' : null);
            if ($acao === null) {
                throw new AcervoException('Seu perfil não pode registrar classificações CDD.');
            }
            $atual = cdd_linha_bloqueada($livroId, $item);
            $vigente = cdd_vigente(['cdd_aprovada' => $atual['cdd_aprovada'] ?? null, 'cdd_proposta' => $atual['cdd_proposta'] ?? null, 'cdd_original' => $atual['cdd_original'] ?? null]);
            if ($vigente['numero'] === $v['numero'] && ($vigente['tipo'] === 'aprovada' || ($vigente['tipo'] === 'proposta' && $acao === 'sugerir'))) {
                return;
            }
            [$nova, $historicos] = cdd_aplicar($acao, $atual, cdd_dados_limpos([
                'cdd' => $v['numero'],
                'fonte_nome' => trim($fonte) !== '' ? $fonte : 'Livro em mãos (reorganização do acervo)',
                'justificativa' => 'CDD ' . ($vigente['numero'] !== '' ? 'corrigida (antes: ' . $vigente['numero'] . ')' : 'informada') . ' na reorganização do acervo, revisada por ' . $usuario['nome'] . '.',
            ]), $usuario);
            cdd_gravar_mysql($nova, $historicos, $usuario);
        } catch (CddErro $e) {
            throw new AcervoException('CDD não registrada: ' . $e->getMessage() . ' Nada foi cadastrado.');
        }
    };
}
