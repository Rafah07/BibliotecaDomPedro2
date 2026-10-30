<?php

namespace Biblioteca\Services;

use Biblioteca\Support\Texto;

/**
 * Regras da importação de alunos: sugestão de colunas, limpeza e validação das linhas
 * e o plano do que será feito com cada uma (incluir, atualizar, ignorar).
 *
 * Não acessa banco nem arquivos: recebe as linhas já lidas e os cadastros existentes.
 * A matrícula é o identificador do aluno. O nome nunca é usado para decidir que dois
 * registros são a mesma pessoa; nomes iguais só geram o aviso de "possível duplicidade".
 */
final class AlunoImportService
{
    public const TAMANHO_MAXIMO = 5 * 1024 * 1024; // 5 MB
    public const MAX_LINHAS = 5000;

    /** Campos da tabela alunos que podem vir da planilha. */
    public const CAMPOS = [
        'nome' => ['label' => 'Nome', 'obrigatorio' => true, 'max' => 150],
        'matricula' => ['label' => 'Matrícula', 'obrigatorio' => true, 'max' => 40],
        'turma' => ['label' => 'Turma', 'obrigatorio' => false, 'max' => 40],
        'turno' => ['label' => 'Turno', 'obrigatorio' => false, 'max' => 20],
        'email' => ['label' => 'E-mail institucional', 'obrigatorio' => false, 'max' => 190],
    ];

    public const TURNOS = ['manha' => 'Manhã', 'tarde' => 'Tarde', 'noite' => 'Noite', 'integral' => 'Integral'];

    private const TURNOS_ACEITOS = [
        'manha' => 'manha', 'matutino' => 'manha', 'm' => 'manha', 'mat' => 'manha',
        'tarde' => 'tarde', 'vespertino' => 'tarde', 't' => 'tarde', 'vesp' => 'tarde',
        'noite' => 'noite', 'noturno' => 'noite', 'n' => 'noite', 'not' => 'noite',
        'integral' => 'integral', 'tempo integral' => 'integral', 'i' => 'integral', 'int' => 'integral',
    ];

    /** Palavras do cabeçalho que indicam cada campo (comparadas sem acento e em minúsculas). */
    private const SINONIMOS = [
        'matricula' => ['matricula', 'n matricula', 'numero matricula', 'numero da matricula', 'ra', 'registro academico', 'codigo do aluno', 'cod aluno'],
        'nome' => ['nome', 'nome do aluno', 'nome completo', 'aluno', 'aluna', 'estudante', 'nome do estudante'],
        'turma' => ['turma', 'classe', 'serie turma', 'sala'],
        'turno' => ['turno', 'periodo'],
        'email' => ['email institucional', 'e mail institucional', 'email escolar', 'e mail escolar', 'email', 'e mail', 'correio eletronico'],
    ];

    /** @param string|null $dominioEmail domínio institucional (ex.: "escola.edu.br"); vazio aceita qualquer domínio */
    public function __construct(private ?string $dominioEmail = null)
    {
        $this->dominioEmail = $dominioEmail ? mb_strtolower(ltrim(trim($dominioEmail), '@')) : null;
    }

    /**
     * Sugere o campo de cada coluna pelo cabeçalho. Colunas desconhecidas ficam "ignorar":
     * dados pessoais que o sistema não usa (CPF, endereço, responsável…) nunca são mapeados sozinhos.
     * @param array<string, string> $cabecalhos letra => título
     * @return array<string, string> letra => campo | 'ignorar'
     */
    public function sugerirMapeamento(array $cabecalhos): array
    {
        $sugestao = array_fill_keys(array_keys($cabecalhos), 'ignorar');
        $usados = [];
        foreach (self::SINONIMOS as $campo => $palavras) {
            foreach ($cabecalhos as $letra => $titulo) {
                if ($sugestao[$letra] !== 'ignorar' || isset($usados[$campo])) {
                    continue;
                }
                if (in_array(Texto::chave($titulo), $palavras, true)) {
                    $sugestao[$letra] = $campo;
                    $usados[$campo] = true;
                }
            }
        }
        return $sugestao;
    }

    /**
     * Confere o mapeamento escolhido. Devolve a mensagem do problema ou null.
     * @param array<string, string> $mapeamento letra => campo | 'ignorar'
     * @param array<string, string> $cabecalhos letra => título
     */
    public function problemaNoMapeamento(array $mapeamento, array $cabecalhos): ?string
    {
        $usados = [];
        foreach ($mapeamento as $letra => $campo) {
            if (!is_string($letra) || !isset($cabecalhos[$letra]) || !is_string($campo)) {
                return 'O mapeamento enviado não corresponde às colunas da planilha.';
            }
            if ($campo === 'ignorar') {
                continue;
            }
            if (!isset(self::CAMPOS[$campo])) {
                return 'O mapeamento enviado não corresponde às colunas da planilha.';
            }
            if (isset($usados[$campo])) {
                return 'O campo "' . self::CAMPOS[$campo]['label'] . '" foi escolhido para mais de uma coluna.';
            }
            $usados[$campo] = true;
        }
        foreach (self::CAMPOS as $campo => $def) {
            if ($def['obrigatorio'] && !isset($usados[$campo])) {
                return 'Indique a coluna de "' . $def['label'] . '". Ela é obrigatória.';
            }
        }
        return null;
    }

    /**
     * Limpa e valida as linhas, só com as colunas mapeadas (as demais são descartadas aqui).
     * @param array<int, array<string, string>> $linhas número da linha => [letra => valor]
     * @param array<string, string> $mapeamento letra => campo | 'ignorar'
     * @return list<array{linha: int, dados: array<string, ?string>, erros: list<string>, avisos: list<string>}>
     */
    public function normalizar(array $linhas, array $mapeamento): array
    {
        $colunas = array_filter($mapeamento, fn ($c) => $c !== 'ignorar');
        $resultado = [];
        foreach ($linhas as $numero => $valores) {
            $dados = array_fill_keys(array_keys(self::CAMPOS), null);
            foreach ($colunas as $letra => $campo) {
                $dados[$campo] = $valores[$letra] ?? '';
            }
            // Linha sem nenhum valor nas colunas usadas: não conta como registro.
            if (implode('', array_map(fn ($v) => trim((string)$v), $dados)) === '') {
                continue;
            }
            $resultado[] = $this->validarLinha((int)$numero, $dados);
        }
        return $resultado;
    }

    /** @param array<string, ?string> $bruto */
    private function validarLinha(int $numero, array $bruto): array
    {
        $erros = [];
        $avisos = [];
        $dados = [];

        $nome = self::limparTexto($bruto['nome']);
        if ($nome === null) {
            $erros[] = 'Nome em branco.';
        } elseif (mb_strlen($nome) > self::CAMPOS['nome']['max']) {
            $erros[] = 'Nome com mais de 150 caracteres.';
        } elseif (!preg_match('/\p{L}/u', $nome)) {
            $erros[] = 'Nome sem letras.';
        }
        $dados['nome'] = $nome;

        // Matrícula: sem espaços nem símbolos estranhos. Não é "corrigida": volta como erro.
        $matricula = self::limparTexto($bruto['matricula']);
        if ($matricula === null) {
            $erros[] = 'Matrícula em branco.';
        } elseif (mb_strlen($matricula) > self::CAMPOS['matricula']['max']) {
            $erros[] = 'Matrícula com mais de 40 caracteres.';
        } elseif (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N}.\/_-]*$/u', $matricula)) {
            $erros[] = 'Matrícula com espaços ou caracteres inválidos (use letras, números, ponto, hífen, barra ou sublinhado).';
        }
        $dados['matricula'] = $matricula;

        $turma = self::limparTexto($bruto['turma']);
        if ($turma !== null && mb_strlen($turma) > self::CAMPOS['turma']['max']) {
            $erros[] = 'Turma com mais de 40 caracteres.';
        }
        $dados['turma'] = $turma;

        $turno = self::limparTexto($bruto['turno']);
        $dados['turno'] = null;
        if ($turno !== null) {
            $dados['turno'] = self::TURNOS_ACEITOS[Texto::chave($turno)] ?? null;
            if ($dados['turno'] === null) {
                $avisos[] = 'Turno não reconhecido (use manhã, tarde, noite ou integral). O turno ficará em branco.';
            }
        }

        $email = self::limparTexto($bruto['email']);
        $dados['email'] = null;
        if ($email !== null) {
            $email = mb_strtolower($email);
            if (mb_strlen($email) > self::CAMPOS['email']['max'] || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $avisos[] = 'E-mail inválido. O e-mail ficará em branco.';
            } elseif ($this->dominioEmail && !str_ends_with($email, '@' . $this->dominioEmail)) {
                $avisos[] = 'O e-mail não é do domínio institucional (@' . $this->dominioEmail . '). O e-mail ficará em branco.';
            } else {
                $dados['email'] = $email;
            }
        }

        return ['linha' => $numero, 'dados' => $dados, 'erros' => $erros, 'avisos' => $avisos];
    }

    /** Texto sem caracteres de controle, espaços extras ou espaços nas pontas. */
    private static function limparTexto(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = (string)$valor;
        if (!mb_check_encoding($texto, 'UTF-8')) {
            $texto = mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
        }
        $texto = preg_replace('/[\x{0000}-\x{001F}\x{007F}\x{200B}-\x{200D}\x{FEFF}\x{00A0}]/u', ' ', $texto);
        $texto = trim(preg_replace('/\s+/u', ' ', $texto));
        return $texto === '' ? null : $texto;
    }

    /** Chave da matrícula para comparação (o banco compara sem diferenciar maiúsculas). */
    public static function chaveMatricula(?string $matricula): string
    {
        return mb_strtoupper((string)$matricula);
    }

    /**
     * Decide o que fazer com cada linha válida.
     *
     * Situações: erro (não entra), novo (incluir), duplicidade (matrícula nova, mas nome ou e-mail
     * já usado por outro aluno), igual (já cadastrado sem diferença), existente (já cadastrado com
     * diferença; só é alterado no modo "atualizar"), repetida (matrícula repetida na planilha: erro).
     *
     * @param list<array> $linhas resultado de normalizar()
     * @param array<string, array> $porMatricula chaveMatricula => cadastro (id, nome, matricula, turma, turno, email, ativo)
     * @param array<string, list<array>> $porNome Texto::chave(nome) => cadastros com esse nome
     * @param array<string, list<array>> $porEmail e-mail => cadastros com esse e-mail
     * @param array{modo?: string, importar_duplicidades?: bool} $opcoes modo "novos" (padrão) ou "atualizar"
     */
    public function planejar(array $linhas, array $porMatricula, array $porNome, array $porEmail, array $opcoes = []): array
    {
        $atualizar = ($opcoes['modo'] ?? 'novos') === 'atualizar';
        $importarDuplicidades = !empty($opcoes['importar_duplicidades']);

        // Matrículas, nomes e e-mails repetidos dentro da própria planilha.
        $linhasPorMatricula = [];
        $linhasPorNome = [];
        $linhasPorEmail = [];
        foreach ($linhas as $l) {
            if ($l['dados']['matricula'] !== null) {
                $linhasPorMatricula[self::chaveMatricula($l['dados']['matricula'])][] = $l['linha'];
            }
            if (!$l['erros']) {
                $linhasPorNome[Texto::chave($l['dados']['nome'])][] = $l;
                if ($l['dados']['email'] !== null) {
                    $linhasPorEmail[$l['dados']['email']][] = $l;
                }
            }
        }

        $itens = [];
        $resumo = ['total' => count($linhas), 'incluir' => 0, 'atualizar' => 0, 'sem_alteracao' => 0, 'ja_cadastrados' => 0,
                   'duplicidades' => 0, 'erros' => 0, 'avisos' => 0, 'ignorados' => 0];
        $acoes = [];

        foreach ($linhas as $l) {
            $d = $l['dados'];
            $item = ['linha' => $l['linha'], 'dados' => $d, 'erros' => $l['erros'], 'avisos' => $l['avisos'],
                     'situacao' => 'erro', 'acao' => 'nenhuma', 'alteracoes' => [], 'atual' => null];
            $chave = self::chaveMatricula($d['matricula']);
            $repetidas = $d['matricula'] !== null ? $linhasPorMatricula[$chave] : [];
            if (count($repetidas) > 1) {
                $outras = array_values(array_diff($repetidas, [$l['linha']]));
                $item['erros'][] = 'Matrícula repetida na planilha (também na linha ' . implode(', ', $outras) . '). Nenhuma dessas linhas será importada.';
            }

            if (!$item['erros']) {
                $existente = $porMatricula[$chave] ?? null;
                if ($existente) {
                    $item['atual'] = array_intersect_key($existente, self::CAMPOS + ['ativo' => 0]);
                    $item['alteracoes'] = $this->alteracoes($existente, $d);
                    if (!(bool)$existente['ativo']) {
                        $item['avisos'][] = 'Este aluno está com o cadastro inativo. A importação não reativa cadastros.';
                    }
                    if (isset($item['alteracoes']['nome']) && Texto::chave($existente['nome']) !== Texto::chave($d['nome'])) {
                        $item['avisos'][] = 'O nome na planilha é diferente do cadastrado para esta matrícula. Confira se a matrícula está correta.';
                    }
                    if (!$item['alteracoes']) {
                        $item['situacao'] = 'igual';
                    } else {
                        $item['situacao'] = 'existente';
                        if ($atualizar) {
                            $item['acao'] = 'atualizar';
                        }
                    }
                } else {
                    $motivos = $this->motivosDuplicidade($l, $porNome, $porEmail, $linhasPorNome, $linhasPorEmail);
                    if ($motivos) {
                        $item['situacao'] = 'duplicidade';
                        $item['avisos'] = array_merge($item['avisos'], $motivos);
                        if ($importarDuplicidades) {
                            $item['acao'] = 'incluir';
                        }
                    } else {
                        $item['situacao'] = 'novo';
                        $item['acao'] = 'incluir';
                    }
                }
            }

            match ($item['situacao']) {
                'erro' => $resumo['erros']++,
                'igual' => $resumo['sem_alteracao']++,
                'existente' => $resumo['ja_cadastrados']++,
                'duplicidade' => $resumo['duplicidades']++,
                default => null,
            };
            if ($item['avisos']) {
                $resumo['avisos']++;
            }
            if ($item['acao'] === 'incluir') {
                $resumo['incluir']++;
                $acoes[] = ['incluir', $d];
            } elseif ($item['acao'] === 'atualizar') {
                $resumo['atualizar']++;
                // O "de" de cada alteração entra na assinatura: se o cadastro mudar depois da prévia, a confirmação é recusada.
                $acoes[] = ['atualizar', (int)$existente['id'], $item['alteracoes']];
            } else {
                $resumo['ignorados']++;
            }
            $itens[] = $item;
        }

        return ['resumo' => $resumo, 'itens' => $itens, 'acoes' => $acoes, 'modo' => $atualizar ? 'atualizar' : 'novos'];
    }

    /**
     * Diferenças entre o cadastro e a planilha. Células vazias nunca apagam o que já está cadastrado.
     * @return array<string, array{de: ?string, para: string}>
     */
    private function alteracoes(array $existente, array $dados): array
    {
        $alteracoes = [];
        foreach (['nome', 'turma', 'turno', 'email'] as $campo) {
            $novo = $dados[$campo];
            if ($novo === null) {
                continue;
            }
            $atual = $existente[$campo] === null ? null : (string)$existente[$campo];
            $igual = $campo === 'email' ? mb_strtolower((string)$atual) === $novo : $atual === $novo;
            if (!$igual) {
                $alteracoes[$campo] = ['de' => $atual, 'para' => $novo];
            }
        }
        return $alteracoes;
    }

    /** Avisos de possível duplicidade para uma matrícula nova. */
    private function motivosDuplicidade(array $linha, array $porNome, array $porEmail, array $linhasPorNome, array $linhasPorEmail): array
    {
        $motivos = [];
        $d = $linha['dados'];
        $chaveNome = Texto::chave($d['nome']);
        foreach ($porNome[$chaveNome] ?? [] as $cad) {
            $motivos[] = 'Já existe um aluno com o mesmo nome e outra matrícula (' . $cad['matricula'] . ').';
        }
        foreach ($linhasPorNome[$chaveNome] ?? [] as $outra) {
            if ($outra['linha'] !== $linha['linha']) {
                $motivos[] = 'Mesmo nome da linha ' . $outra['linha'] . ' da planilha, com outra matrícula.';
            }
        }
        if ($d['email'] !== null) {
            foreach ($porEmail[$d['email']] ?? [] as $cad) {
                $motivos[] = 'O e-mail já está no cadastro de outro aluno (matrícula ' . $cad['matricula'] . ').';
            }
            foreach ($linhasPorEmail[$d['email']] ?? [] as $outra) {
                if ($outra['linha'] !== $linha['linha']) {
                    $motivos[] = 'Mesmo e-mail da linha ' . $outra['linha'] . ' da planilha.';
                }
            }
        }
        return array_values(array_unique($motivos));
    }

    /**
     * Resumo assinado do que será gravado. A confirmação só é aceita se o plano recalculado
     * no momento da gravação for idêntico ao que foi mostrado na prévia.
     */
    public static function assinatura(array $plano, string $arquivoHash, string $chave): string
    {
        $conteudo = json_encode([$arquivoHash, $plano['modo'], $plano['acoes']], JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', (string)$conteudo, $chave);
    }
}
