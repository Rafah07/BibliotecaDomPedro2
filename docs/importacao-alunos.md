# Importação local de alunos

Página do painel: **Gerenciamento → Importar Alunos** (`admin/importar-alunos.php`).

Inclui ou atualiza o cadastro da tabela `alunos` a partir de uma planilha `.xlsx` ou `.csv` escolhida no navegador do computador onde o XAMPP está rodando. Foi feita para importar a planilha real da escola **só no computador da biblioteca**: todo o desenvolvimento e os testes usaram alunos fictícios.

## Quem pode usar

Permissão `alunos.importar`, dada aos perfis **Administrador** e **Bibliotecária**. Auxiliar e Somente consulta não veem o item no menu e recebem 403 na página e na API. É preciso ter o MySQL configurado: no modo demonstrativo a página só mostra um aviso.

Alunos **não** recebem conta, login nem senha. A importação não mexe na tabela `administradores`.

## Passo a passo

1. **Escolher arquivo.** `.xlsx` ou `.csv` (separado por `;`, `,`, tab ou `|`; UTF-8 ou Windows-1252), até 5 MB e 5.000 linhas por aba. O botão “Baixar modelo” gera um CSV de exemplo com dois alunos fictícios.
2. **Mapear colunas.** Escolha a aba e a linha do cabeçalho. Para cada coluna, indique o campo: Nome, Matrícula, Turma, Turno, E-mail institucional ou **Ignorar coluna**. Nome e Matrícula são obrigatórios. Colunas que o sistema não reconhece (CPF, endereço, responsáveis, telefone, data de nascimento…) já vêm como “Ignorar”. Os exemplos mostrados na tela servem só para reconhecer a coluna.
3. **Prévia.** Mostra o total de registros, quantos serão incluídos ou atualizados, os já cadastrados, as possíveis duplicidades, os erros e os avisos, com filtros por situação. Nada é gravado aqui.
4. **Confirmar.** Uma janela resume o que será feito. Tudo é gravado numa única transação: se qualquer linha falhar, nada muda. Depois aparece o relatório das linhas não importadas (linha da planilha, matrícula e motivo), que pode ser baixado em CSV.

## Regras

- **A matrícula identifica o aluno.** Ela é comparada sem diferenciar maiúsculas de minúsculas (como o índice único do banco). O nome nunca é usado para decidir que dois registros são a mesma pessoa.
- **Matrícula nova:** o aluno é incluído (ativo, sem conta de acesso).
- **Matrícula já cadastrada:** por padrão, o cadastro **não é alterado** (“Somente incluir alunos novos”). Para mudar dados existentes, escolha “Incluir novos e atualizar existentes”. A prévia mostra cada alteração (valor atual riscado → valor novo), e a importação só é liberada depois de marcar “Revisei as alterações e quero atualizar esses cadastros”. O servidor confere essa confirmação de novo.
  - Só nome, turma, turno e e-mail podem mudar. A matrícula, o status ativo/inativo, as observações e os empréstimos não são tocados.
  - Células vazias **não apagam** dados já cadastrados.
  - Se o nome da planilha for muito diferente do cadastrado para a mesma matrícula, aparece um aviso para conferir a matrícula.
  - Cadastros inativos são avisados e não são reativados.
- **Possível duplicidade:** matrícula nova, mas com o mesmo nome (sem diferenciar acentos e maiúsculas) ou o mesmo e-mail de outro aluno cadastrado ou de outra linha da planilha. Essas linhas **não entram** por padrão. Depois de conferir que são pessoas diferentes (homônimos), marque “Incluir também as linhas marcadas como possível duplicidade”.
- **Erros (a linha não entra):** nome em branco ou sem letras, matrícula em branco, com espaços ou símbolos (aceita letras, números, `.`, `-`, `/`, `_`), textos acima do tamanho da coluna, e **matrícula repetida dentro da planilha** (nenhuma das linhas repetidas entra).
- **Avisos (a linha entra, o campo fica em branco):** turno não reconhecido (aceita manhã/matutino/M, tarde/vespertino/T, noite/noturno/N, integral/I) e e-mail inválido.
- **E-mail institucional:** opcionalmente, defina `ALUNOS_EMAIL_DOMINIO=escola.edu.br` no `.env`. Assim, e-mails de outros domínios viram aviso e não são gravados.
- **Prévia desatualizada:** a prévia gera uma assinatura do que será gravado (arquivo, opções e cada inclusão/alteração). Na confirmação o servidor refaz a análise com os cadastros travados (`SELECT … FOR UPDATE`) e só grava se o resultado for idêntico. Se o arquivo, as opções ou algum cadastro mudaram nesse meio-tempo, pede uma nova prévia. Se outra pessoa cadastrar a mesma matrícula durante a gravação, o índice único recusa e nada é gravado.

## Privacidade e segurança

- **O arquivo não é guardado.** O navegador mantém o arquivo escolhido em memória e o reenvia ao servidor local em cada etapa. O PHP lê direto do arquivo temporário do upload, que ele mesmo apaga ao fim da requisição. Nada vai para `storage/` (diferente da importação do acervo) e nada é salvo no navegador (`localStorage`).
- **Só as colunas mapeadas são analisadas.** O conteúdo das colunas ignoradas é descartado logo após a leitura e não aparece na prévia nem no relatório.
- **Nenhum serviço externo** é chamado: não há API, IA, telemetria nem biblioteca de planilha por CDN. A leitura usa o PhpSpreadsheet instalado pelo Composer. (O layout do painel continua carregando a fonte do Google Fonts e o Chart.js do cdnjs, como todas as páginas. São só arquivos estáticos, sem dados, e a política `Referrer-Policy: same-origin` impede que o endereço da página seja enviado.)
- **Nada de dados de alunos em logs, URLs ou mensagens de erro.** Todas as ações usam POST. O registro em `acoes_administrativas` (`alunos.importar`) guarda só contagens (linhas, incluídos, atualizados, não importados, erros). Erros inesperados registram no log do servidor só o tipo e o local do erro, nunca a mensagem (mensagens do MySQL podem conter valores das linhas), e o usuário vê uma mensagem genérica.
- **O relatório de linhas não importadas** é gerado no navegador (download local), traz só linha, matrícula e motivo, e protege as células contra fórmulas ao abrir no Excel.
- **Validações:** extensão e tipo real do arquivo (`finfo`), `.xlsx` sem macros nem objetos incorporados, proteção contra “zip bomb”, limite de 5 MB e 5.000 linhas, até 60 colunas, mapeamento conferido no servidor contra as colunas reais.
- **CSRF** em todas as chamadas (cabeçalho `X-CSRF-Token`). **SQL injection:** só consultas preparadas, e nomes de colunas vêm de listas fixas do código. **Acesso:** login e permissão conferidos no servidor em cada requisição.

## Arquivos

| Arquivo | Papel |
| --- | --- |
| `admin/importar-alunos.php` | Página (assistente em 4 etapas) |
| `admin/api/alunos/importar.php` | API: `ler`, `previa`, `confirmar` (POST) e `modelo` (GET) |
| `admin/includes/alunos-importacao.php` | Leitura do upload, análise, assinatura e gravação transacional |
| `src/Services/AlunoImportService.php` | Regras: sugestão de colunas, validação, duplicidades e plano |
| `src/Repositories/AlunoRepository.php` | Consultas e gravações na tabela `alunos` |
| `admin/assets/js/importar-alunos.js`, `admin/assets/css/importar-alunos.css` | Interface |
| `admin/tests/alunos-importacao-teste.php` | Testes com banco temporário e alunos fictícios |

Não há migração nova: a tabela `alunos` (migração `010`) já tinha `matricula` única.

## Importando a planilha real (no computador da biblioteca)

1. Atualize o projeto pelo VS Code (`git pull`) e rode `composer install` se o PhpSpreadsheet ainda não estiver instalado (ele é necessário para `.xlsx`; sem ele, salve a planilha como CSV).
2. Faça um backup do banco antes da primeira importação (phpMyAdmin → Exportar).
3. Se a matrícula tiver zeros à esquerda, confira no Excel se a coluna está como **Texto**. Se estiver como número, o Excel já removeu os zeros no próprio arquivo.
4. Entre no painel com uma conta de Administrador ou Bibliotecária e abra **Importar Alunos**.
5. Escolha a aba com o cadastro dos alunos (a planilha pode ter outras abas, como a de empréstimos: elas não são lidas).
6. Mapeie só Nome, Matrícula, Turma, Turno e E-mail. Deixe todas as outras colunas como “Ignorar coluna”.
7. Revise a prévia, principalmente as abas “Erros” e “Possíveis duplicidades”, e confirme. Se precisar corrigir algo, corrija na planilha e importe de novo: as matrículas já incluídas aparecem como “sem alterações” e não são duplicadas.

## Testes

```bash
php admin/tests/alunos-importacao-teste.php
```

Cria e apaga um banco temporário (`bdp_teste_alunos_XXXX`, nunca o `DB_NAME` do `.env`), gera planilhas `.csv` e `.xlsx` fictícias numa pasta temporária e testa: sugestão de colunas, validações, duplicidades, modo “somente novos”, atualização com confirmação, prévia desatualizada, tudo ou nada (falha simulada no meio da gravação), limite de linhas, 5.000 alunos em poucos segundos, abas e cabeçalho do `.xlsx`, permissões por perfil, CSRF, arquivos falsos ou grandes demais, ausência de dados no log, nenhum arquivo deixado no servidor e preservação de livros, exemplares, administradores, importações e empréstimos.
