<?php

namespace Biblioteca\Services;

use Biblioteca\Repositories\SiteConfiguracaoRepository;
use Biblioteca\Support\RegrasCirculacao;
use Throwable;

/**
 * Informações institucionais do site público (página Sobre, Regras, rodapé e página inicial).
 *
 * Nenhuma informação oficial é inventada: horários, contatos, regras e textos sobre a escola
 * só aparecem depois de preenchidos no painel (Sistema → Site público). Até lá, o site
 * mostra que a informação aguarda confirmação da escola.
 * A única exceção é a mensagem de boas-vindas, que tem um texto neutro padrão.
 */
final class SiteConteudo
{
    public const MENSAGEM_PENDENTE = 'Informação aguardando confirmação da escola.';

    /**
     * Campos editáveis. 'tipo': linha (uma linha), texto (parágrafos; linhas com "- " viram lista),
     * email ou opcao (sim/não).
     */
    public const CAMPOS = [
        'escola_nome' => ['grupo' => 'Identificação', 'rotulo' => 'Nome da escola', 'tipo' => 'linha', 'max' => 150,
            'ajuda' => 'Como a escola deve ser identificada no rodapé e na página Sobre.'],
        'boas_vindas' => ['grupo' => 'Identificação', 'rotulo' => 'Mensagem de boas-vindas (página inicial)', 'tipo' => 'texto', 'max' => 600,
            'ajuda' => 'Vazio: usa uma mensagem neutra padrão.'],
        'apresentacao' => ['grupo' => 'Sobre a biblioteca', 'rotulo' => 'Apresentação da biblioteca', 'tipo' => 'texto', 'max' => 3000,
            'ajuda' => 'Quem somos. Use somente informações confirmadas pela escola.'],
        'espaco' => ['grupo' => 'Sobre a biblioteca', 'rotulo' => 'O espaço', 'tipo' => 'texto', 'max' => 2000,
            'ajuda' => 'Ambientes, estrutura e recursos disponíveis.'],
        'objetivos' => ['grupo' => 'Sobre a biblioteca', 'rotulo' => 'Objetivos da biblioteca escolar', 'tipo' => 'texto', 'max' => 2000,
            'ajuda' => 'Comece cada item com "- " para mostrar como lista.'],
        'orientacoes' => ['grupo' => 'Sobre a biblioteca', 'rotulo' => 'Orientações gerais de uso', 'tipo' => 'texto', 'max' => 2000,
            'ajuda' => 'Comportamento no espaço, uso das mesas, silêncio etc.'],
        'horario' => ['grupo' => 'Atendimento e contato', 'rotulo' => 'Horário de funcionamento', 'tipo' => 'texto', 'max' => 1000,
            'ajuda' => 'Ex.: uma linha por dia ou turno, como "- Segunda a sexta: ...".'],
        'endereco' => ['grupo' => 'Atendimento e contato', 'rotulo' => 'Localização / endereço', 'tipo' => 'linha', 'max' => 255, 'ajuda' => ''],
        'telefone' => ['grupo' => 'Atendimento e contato', 'rotulo' => 'Telefone institucional', 'tipo' => 'linha', 'max' => 60, 'ajuda' => ''],
        'email' => ['grupo' => 'Atendimento e contato', 'rotulo' => 'E-mail institucional', 'tipo' => 'email', 'max' => 150,
            'ajuda' => 'Somente o e-mail oficial da biblioteca ou da escola. Nunca e-mails pessoais.'],
        'contato_observacao' => ['grupo' => 'Atendimento e contato', 'rotulo' => 'Observação sobre o atendimento', 'tipo' => 'texto', 'max' => 600, 'ajuda' => ''],
        'regras_gerais' => ['grupo' => 'Regras e funcionamento', 'rotulo' => 'Regras gerais da biblioteca', 'tipo' => 'texto', 'max' => 3000,
            'ajuda' => 'Somente regras aprovadas pela escola. Não publique multas ou punições que não estejam no regulamento.'],
        'emprestimo_info' => ['grupo' => 'Regras e funcionamento', 'rotulo' => 'Informações adicionais sobre empréstimos', 'tipo' => 'texto', 'max' => 1500,
            'ajuda' => 'Ex.: quantos livros cada aluno pode levar, se aprovado pela escola.'],
        'devolucao_info' => ['grupo' => 'Regras e funcionamento', 'rotulo' => 'Informações adicionais sobre devoluções', 'tipo' => 'texto', 'max' => 1500, 'ajuda' => ''],
        'mostrar_prazo' => ['grupo' => 'Regras e funcionamento', 'rotulo' => 'Mostrar o prazo de empréstimo no site', 'tipo' => 'opcao', 'max' => 1,
            'ajuda' => 'Marque somente quando o prazo configurado no sistema (EMPRESTIMO_PRAZO_DIAS no .env) tiver sido aprovado pela escola.'],
        'creditos' => ['grupo' => 'Rodapé', 'rotulo' => 'Créditos do projeto', 'tipo' => 'linha', 'max' => 255,
            'ajuda' => 'Ex.: quem desenvolveu o site. Vazio: nenhum crédito é exibido.'],
    ];

    private const BOAS_VINDAS_PADRAO = 'Pesquise o acervo da biblioteca, descubra novas leituras e veja quais livros estão disponíveis para empréstimo presencial.';

    /** @var array<string, string> */
    private array $valores = [];

    /**
     * @param SiteConfiguracaoRepository|null $repo null quando o banco não está configurado
     * @param RegrasCirculacao|null $regras regras de empréstimo do .env (para o prazo)
     */
    public function __construct(?SiteConfiguracaoRepository $repo, private ?RegrasCirculacao $regras = null)
    {
        if ($repo) {
            try {
                $this->valores = $repo->todos();
            } catch (Throwable $e) {
                // Migração 080 ainda não aplicada ou banco fora do ar: o site continua, com tudo "a confirmar".
                error_log('[biblioteca] Textos do site público indisponíveis: ' . $e->getMessage());
            }
        }
    }

    public function valor(string $chave): string
    {
        $valor = trim($this->valores[$chave] ?? '');
        if ($valor === '' && $chave === 'boas_vindas') {
            return self::BOAS_VINDAS_PADRAO;
        }
        return $valor;
    }

    public function preenchido(string $chave): bool
    {
        return trim($this->valores[$chave] ?? '') !== '';
    }

    /** Prazo de empréstimo em dias, só quando configurado no .env E liberado para o site. */
    public function prazoEmprestimo(): ?int
    {
        if (!$this->regras || $this->regras->prazoProvisorio || ($this->valores['mostrar_prazo'] ?? '') !== '1') {
            return null;
        }
        return $this->regras->prazoDias;
    }

    /**
     * Valida o formulário do painel.
     * @return array{0: array<string, string>, 1: array<string, string>} [valores, erros]
     */
    public static function validar(array $post): array
    {
        $valores = [];
        $erros = [];
        foreach (self::CAMPOS as $chave => $campo) {
            $bruto = $post[$chave] ?? '';
            $texto = is_string($bruto) ? str_replace("\r\n", "\n", $bruto) : '';
            // Sem caracteres de controle (exceto quebra de linha) e sem espaços nas pontas.
            $texto = trim(preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $texto) ?? '');
            if ($campo['tipo'] !== 'texto') {
                $texto = preg_replace('/\s+/u', ' ', $texto) ?? '';
            }
            if ($campo['tipo'] === 'opcao') {
                $texto = $texto === '1' ? '1' : '0';
            }
            if (mb_strlen($texto) > $campo['max']) {
                $erros[$chave] = 'Use no máximo ' . $campo['max'] . ' caracteres.';
            }
            if ($campo['tipo'] === 'email' && $texto !== '' && !filter_var($texto, FILTER_VALIDATE_EMAIL)) {
                $erros[$chave] = 'Informe um e-mail válido.';
            }
            $valores[$chave] = $texto;
        }
        return [$valores, $erros];
    }
}
