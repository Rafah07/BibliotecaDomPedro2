<?php

namespace Biblioteca\Support;

/**
 * Configurações gerais editáveis no painel (Sistema → Configurações, Etapa 6).
 *
 * Cada chave tem o MESMO nome da variável do .env. A ordem de prioridade é:
 *   1. valor salvo no painel (tabela configuracoes_sistema, migração 090);
 *   2. valor do .env (ou variável do servidor);
 *   3. padrão do sistema (o mesmo de antes desta etapa).
 * Deixar um campo vazio no painel apaga o valor salvo e volta a usar o .env/padrão.
 *
 * Só as chaves listadas em CAMPOS podem ser sobrescritas pelo painel: banco de dados,
 * ambiente, fuso horário, caminhos de pastas e fontes CDD continuam só no .env
 * (são técnicos, e um valor errado pode tirar o sistema do ar).
 *
 * 'tipo': inteiro (min/max), opcao (sim/não), linha (uma linha), texto (várias linhas) ou dominio.
 * 'permissao': permissão necessária para alterar o campo (conferida no servidor).
 * 'padrao': como o sistema se comporta quando nada está definido (texto para a tela).
 */
final class ConfiguracaoSistema
{
    public const GRUPOS = [
        'identificacao' => ['titulo' => 'Identificação', 'icone' => 'library-big',
            'descricao' => 'Nome da biblioteca usado nos materiais impressos. Os textos do site público ficam em Sistema → Site público.'],
        'circulacao' => ['titulo' => 'Regras de empréstimo', 'icone' => 'book-open-check',
            'descricao' => 'Prazos, renovações e limites aplicados em novos empréstimos e renovações. Empréstimos já registrados mantêm as datas que tinham.'],
        'alunos' => ['titulo' => 'Cadastro de alunos', 'icone' => 'graduation-cap',
            'descricao' => 'Regras usadas na importação de alunos.'],
        'seguranca' => ['titulo' => 'Segurança do painel', 'icone' => 'shield-check',
            'descricao' => 'Tempo de sessão e proteção contra tentativas de senha. Somente o administrador altera.'],
        'manutencao' => ['titulo' => 'Site público em manutenção', 'icone' => 'construction',
            'descricao' => 'Tira o site público do ar temporariamente (o painel continua funcionando). Somente o administrador altera.'],
    ];

    public const CAMPOS = [
        'ETIQUETA_BIBLIOTECA' => ['grupo' => 'identificacao', 'rotulo' => 'Nome impresso nas etiquetas', 'tipo' => 'linha', 'max' => 60,
            'padrao' => 'Biblioteca Dom Pedro II', 'permissao' => 'configuracoes.editar',
            'ajuda' => 'Aparece no topo de cada etiqueta dos exemplares. Não use dados pessoais.'],

        'EMPRESTIMO_PRAZO_DIAS' => ['grupo' => 'circulacao', 'rotulo' => 'Prazo padrão do empréstimo (dias)', 'tipo' => 'inteiro', 'min' => 1, 'max' => 365,
            'padrao' => '14 dias (valor PROVISÓRIO)', 'permissao' => 'configuracoes.editar',
            'ajuda' => 'Preencha somente com o prazo aprovado pela escola. Enquanto estiver vazio, o sistema usa 14 dias e avisa que é provisório.'],
        'EMPRESTIMO_RENOVACAO_DIAS' => ['grupo' => 'circulacao', 'rotulo' => 'Dias acrescentados em cada renovação', 'tipo' => 'inteiro', 'min' => 1, 'max' => 365,
            'padrao' => 'o mesmo prazo do empréstimo', 'permissao' => 'configuracoes.editar', 'ajuda' => ''],
        'EMPRESTIMO_MAX_RENOVACOES' => ['grupo' => 'circulacao', 'rotulo' => 'Limite de renovações por empréstimo', 'tipo' => 'inteiro', 'min' => 0, 'max' => RegrasCirculacao::RENOVACOES_LIMITE_TECNICO,
            'padrao' => 'sem limite da escola (limite técnico de ' . RegrasCirculacao::RENOVACOES_LIMITE_TECNICO . ')', 'permissao' => 'configuracoes.editar',
            'ajuda' => 'Use 0 para não permitir renovações.'],
        'EMPRESTIMO_RENOVAR_ATRASADO' => ['grupo' => 'circulacao', 'rotulo' => 'Permitir renovar empréstimo atrasado', 'tipo' => 'opcao',
            'padrao' => 'sim', 'permissao' => 'configuracoes.editar', 'ajuda' => ''],
        'EMPRESTIMO_PRAZO_MAXIMO_DIAS' => ['grupo' => 'circulacao', 'rotulo' => 'Maior prazo aceito ao escolher a data manualmente (dias)', 'tipo' => 'inteiro', 'min' => 1, 'max' => 730,
            'padrao' => '365 dias', 'permissao' => 'configuracoes.editar',
            'ajuda' => 'Vale para a data escolhida pela bibliotecária no empréstimo e na renovação.'],
        'EMPRESTIMO_LIMITE_ALUNO' => ['grupo' => 'circulacao', 'rotulo' => 'Máximo de livros emprestados ao mesmo tempo por aluno', 'tipo' => 'inteiro', 'min' => 1, 'max' => 20,
            'padrao' => 'sem limite', 'permissao' => 'configuracoes.editar',
            'ajuda' => 'Conta os empréstimos em aberto do aluno. Vazio: sem limite.'],
        'EMPRESTIMO_BLOQUEAR_ATRASO' => ['grupo' => 'circulacao', 'rotulo' => 'Impedir novo empréstimo para aluno com livro atrasado', 'tipo' => 'opcao',
            'padrao' => 'não (o empréstimo é permitido)', 'permissao' => 'configuracoes.editar',
            'ajuda' => 'Ative somente se essa regra fizer parte do regulamento aprovado pela escola.'],

        'ALUNOS_EMAIL_DOMINIO' => ['grupo' => 'alunos', 'rotulo' => 'Domínio do e-mail institucional dos alunos', 'tipo' => 'dominio', 'max' => 120,
            'padrao' => 'qualquer e-mail válido', 'permissao' => 'configuracoes.editar',
            'ajuda' => 'Ex.: escola.edu.br. Preenchido, e-mails de outros domínios ficam em branco na importação.'],

        'PAINEL_INATIVIDADE_MINUTOS' => ['grupo' => 'seguranca', 'rotulo' => 'Encerrar a sessão após quantos minutos sem uso', 'tipo' => 'inteiro', 'min' => 10, 'max' => 480,
            'padrao' => '120 minutos', 'permissao' => 'sistema.manutencao',
            'ajuda' => 'Computadores compartilhados da escola pedem um tempo menor.'],
        'LOGIN_MAX_TENTATIVAS' => ['grupo' => 'seguranca', 'rotulo' => 'Tentativas de senha antes de bloquear por 15 minutos', 'tipo' => 'inteiro', 'min' => 3, 'max' => 20,
            'padrao' => '5 tentativas', 'permissao' => 'sistema.manutencao', 'ajuda' => ''],

        'SITE_MANUTENCAO' => ['grupo' => 'manutencao', 'rotulo' => 'Colocar o site público em manutenção', 'tipo' => 'opcao',
            'padrao' => 'não (site no ar)', 'permissao' => 'sistema.manutencao',
            'ajuda' => 'Os visitantes veem só a mensagem abaixo. O painel administrativo não é afetado.'],
        'SITE_MANUTENCAO_MENSAGEM' => ['grupo' => 'manutencao', 'rotulo' => 'Mensagem exibida durante a manutenção', 'tipo' => 'texto', 'max' => 500,
            'padrao' => self::MENSAGEM_MANUTENCAO_PADRAO, 'permissao' => 'sistema.manutencao', 'ajuda' => ''],
    ];

    public const MENSAGEM_MANUTENCAO_PADRAO = 'O site da biblioteca está em manutenção. Volte em alguns minutos.';

    /** Valor usado quando o campo de opção vale "sim"/"não". */
    public static function opcao(?string $valor): ?bool
    {
        return $valor === null || trim($valor) === '' ? null : filter_var($valor, FILTER_VALIDATE_BOOL);
    }

    /**
     * Valida o formulário. Só os campos que o usuário pode alterar são lidos ($podeAlterar).
     * Valor '' significa "voltar a usar o .env/padrão" (a linha do painel é removida).
     *
     * @param array<string, mixed> $post
     * @param callable(string): bool $podeAlterar recebe a permissão do campo
     * @param callable(string): ?string $efetivo valor efetivo atual de outra chave (para regras entre campos)
     * @return array{0: array<string, string>, 1: array<string, string>} [valores, erros]
     */
    public static function validar(array $post, callable $podeAlterar, callable $efetivo): array
    {
        $valores = [];
        $erros = [];
        foreach (self::CAMPOS as $chave => $campo) {
            if (!$podeAlterar($campo['permissao']) || !array_key_exists($chave, $post)) {
                continue;
            }
            $bruto = $post[$chave];
            $texto = is_string($bruto) ? str_replace("\r\n", "\n", $bruto) : '';
            $texto = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $texto) ?? '');
            if ($campo['tipo'] !== 'texto') {
                $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';
            }
            $erro = null;
            switch ($campo['tipo']) {
                case 'inteiro':
                    if ($texto !== '' && (!preg_match('/^\d{1,4}$/', $texto) || (int)$texto < $campo['min'] || (int)$texto > $campo['max'])) {
                        $erro = 'Informe um número inteiro de ' . $campo['min'] . ' a ' . $campo['max'] . '.';
                    } elseif ($texto !== '') {
                        $texto = (string)(int)$texto;
                    }
                    break;
                case 'opcao':
                    if (!in_array($texto, ['', '1', '0'], true)) {
                        $erro = 'Escolha uma das opções.';
                    }
                    break;
                case 'dominio':
                    $texto = mb_strtolower(ltrim($texto, '@'));
                    if ($texto !== '' && !preg_match('/^(?=.{3,120}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $texto)) {
                        $erro = 'Informe só o domínio, sem "@" e sem espaços (ex.: escola.edu.br).';
                    }
                    break;
            }
            if ($erro === null && isset($campo['max']) && $campo['tipo'] !== 'inteiro' && mb_strlen($texto) > $campo['max']) {
                $erro = 'Use no máximo ' . $campo['max'] . ' caracteres.';
            }
            if ($erro !== null) {
                $erros[$chave] = $erro;
            }
            $valores[$chave] = $texto;
        }

        // Regras entre campos, com o valor que ficará valendo depois de salvar.
        $final = function (string $chave) use ($valores, $efetivo): ?int {
            $v = array_key_exists($chave, $valores) ? ($valores[$chave] !== '' ? $valores[$chave] : null) : $efetivo($chave);
            return $v !== null && preg_match('/^\d+$/', trim($v)) ? (int)$v : null;
        };
        $maximo = $final('EMPRESTIMO_PRAZO_MAXIMO_DIAS') ?? 365;
        foreach (['EMPRESTIMO_PRAZO_DIAS' => 'O prazo padrão', 'EMPRESTIMO_RENOVACAO_DIAS' => 'Os dias da renovação'] as $chave => $nome) {
            $v = $final($chave);
            if ($v !== null && $v > $maximo && !isset($erros[$chave])) {
                $alvo = array_key_exists($chave, $valores) ? $chave : 'EMPRESTIMO_PRAZO_MAXIMO_DIAS';
                $erros[$alvo] = $nome . ' (' . $v . ' dias) não pode passar do prazo máximo (' . $maximo . ' dias).';
            }
        }
        return [$valores, $erros];
    }

    /** Texto do valor para a tela e o histórico (sim/não, "N dias"...). */
    public static function exibir(string $chave, ?string $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }
        $campo = self::CAMPOS[$chave] ?? null;
        if ($campo && $campo['tipo'] === 'opcao') {
            return self::opcao($valor) ? 'sim' : 'não';
        }
        return $valor;
    }
}
