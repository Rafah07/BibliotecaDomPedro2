# Alunos e categorias (Etapa 11)

Dois módulos administrativos que estavam incompletos: a **consulta e manutenção dos alunos**
(antes só existia a importação por planilha) e o **cadastro real de categorias** (antes a
página era demonstrativa e não gravava nada).

Nenhuma migração foi necessária: as tabelas e os campos já existiam desde a migração 001
(`categorias`, `livro_categorias`) e a 010 (`alunos`, com o campo `ativo`). O registro das
alterações usa a tabela `acoes_administrativas` (migração 030), como os demais módulos.

---

## 1. Alunos

### Páginas

| Página | Para quê | Permissão |
| --- | --- | --- |
| `admin/alunos.php` (menu **Gerenciamento → Alunos**) | Lista com pesquisa, filtros, ordenação e paginação | `alunos.ver` |
| `admin/aluno.php?id=` | Detalhes: dados cadastrais, empréstimos ativos, atrasados, devoluções anteriores, histórico de alterações; inativar/reativar | `alunos.ver` (inativar: `alunos.inativar`) |
| `admin/aluno-editar.php` | Cadastro individual (sem `id`) e correção (com `id`) | `alunos.editar` |

O histórico completo de circulação continua em `admin/historico-aluno.php` (botões de ida e
volta entre as duas páginas). Nenhum dado de circulação é copiado: tudo vem das tabelas
`emprestimos` e `emprestimo_renovacoes`.

### Campos usados

Somente os que a importação já usa: **nome, matrícula, turma, turno e e-mail institucional**, mais
a situação (`ativo`) e as datas de cadastro/atualização. Os campos `telefone` e `observacoes` da
tabela não aparecem nem são editados (minimização de dados pessoais).

### Listagem

- Pesquisa por nome, matrícula ou e-mail; cada palavra precisa aparecer. A collation do banco
  (`utf8mb4_unicode_ci`) ignora acentos e maiúsculas nos dois sentidos (`joao` acha "João" e
  `José` acha "Jose"). `%` e `_` são tratados como texto.
- Filtros: turma (as existentes, ou "Sem turma"), turno, situação (Ativos — padrão —, Inativos,
  Todos) e empréstimos (com livro emprestado, com atraso, sem empréstimo em aberto).
- Ordenação por nome, matrícula ou turma; 25, 50 ou 100 por página.
- Paginação no servidor (`LIMIT`/`OFFSET`); as contagens de empréstimos são calculadas só para as
  linhas da página, pelo índice `emprestimos_aluno_idx`. Nunca carrega todos os alunos.

### Validação (as mesmas regras da importação)

`AlunoCadastroService` chama `AlunoImportService::validarCadastro()`, que usa **a mesma função** que
valida cada linha da planilha (`validarLinha`). Não existe um segundo conjunto de regras:

- nome obrigatório, até 150 caracteres, com letras; espaços repetidos e caracteres invisíveis removidos;
- matrícula obrigatória, até 40 caracteres, só letras, números, `.`, `-`, `/`, `_`, sem espaços;
- turma até 40 caracteres; turno manhã/tarde/noite/integral;
- e-mail em minúsculas, formato válido e, se configurado, do domínio institucional
  (`ALUNOS_EMAIL_DOMINIO`, Sistema → Configurações).

Diferença única e intencional: na planilha, e-mail ou turno inválido vira **aviso** e o campo fica em
branco; no formulário, vira **erro**, para a pessoa corrigir na hora.

Conferências feitas no servidor, dentro de uma transação com a linha do aluno bloqueada (`FOR UPDATE`):

- **Matrícula duplicada**: recusada (sem diferenciar maiúsculas), inclusive numa corrida entre dois
  cadastros (o índice único `alunos_matricula_uk` é traduzido em mensagem clara).
- **Possível duplicidade** (mesmo nome, sem diferenciar acentos, ou mesmo e-mail de outro aluno):
  mostra os cadastros parecidos e só grava com a confirmação "é outro aluno", como na prévia da importação.
- **Troca de matrícula**: exige uma confirmação explícita ("Conferi com a secretaria").
- Corrigir nunca muda o `id`: empréstimos, devoluções e renovações continuam ligados ao aluno.

### Inativação

- O campo `alunos.ativo` já existia e o `EmprestimoService` já recusava empréstimo para aluno
  inativo. Agora a bibliotecária inativa e reativa pela página do aluno (com motivo opcional).
- Inativar **não** encerra nem apaga empréstimos: os que estão em aberto podem ser devolvidos e
  renovados normalmente. Não existe exclusão de alunos.
- A importação continua sem reativar cadastros inativos (comportamento anterior preservado).

### Auditoria

Cada gravação entra em `acoes_administrativas` na mesma transação:

| Ação | Detalhes guardados |
| --- | --- |
| `aluno.cadastrar` | origem "cadastro individual" |
| `aluno.editar` | nomes dos campos alterados; quando a matrícula muda, a matrícula anterior |
| `aluno.inativar` / `aluno.reativar` | motivo, se informado |

Os valores novos (nome, e-mail etc.) **não** são copiados para o registro. As ações aparecem na
página do aluno (Histórico de alterações) e em Relatórios → Atividades (módulo Alunos).

---

## 2. Categorias

### Páginas

| Página | Para quê | Permissão |
| --- | --- | --- |
| `admin/categorias.php` (menu **Principal → Categorias**) | Lista real com pesquisa, situação, ordenação e paginação; desativar/reativar; aba das categorias das planilhas antigas | ver: todos; alterar: `categorias.gerenciar` |
| `admin/categoria.php` | Cadastro (sem `id`) e edição (com `id`), com o histórico da categoria | `categorias.gerenciar` |

### Regras (`src/Services/CategoriaService.php`)

- **Nome**: obrigatório, 2 a 80 caracteres, com letras; aceita letras, números, espaços e `, . ; : ( ) / & + ' -`.
  Espaços extras são removidos.
- **Sem duplicidade**: a comparação usa `Texto::chave()` (sem maiúsculas, acentos, espaços e
  pontuação): "Ficção Científica", "ficcao cientifica" e "Ficção-científica" são a mesma. Se a
  igual estiver desativada, o sistema orienta reativá-la. O banco reforça a regra para as ativas
  (índice único `categorias_nome_ativo_uk`, collation sem acentos e maiúsculas).
- **Descrição**: opcional, até 255 caracteres.
- **Desativação** (`arquivado_em`): só para categorias **sem livros ativos**. Com livros, o
  botão vira "Em uso" e o servidor recusa. Os vínculos em `livro_categorias` nunca são apagados;
  livros arquivados mantêm o vínculo. **Não há exclusão** nem exclusão em cascata.
- **Renomear** muda o texto em todo o sistema, porque os livros guardam só o `id`.
- **Quantidade de livros**: contagem real dos livros ativos ligados à categoria (principal ou não).
- **Possíveis repetidas**: se cadastros antigos tiverem nomes equivalentes, a página mostra um
  aviso. Nada é unificado sozinho.
- **Planilhas antigas**: a aba "Categorias das planilhas antigas" lista o texto de
  `registros_legados.categoria_original` com a categoria equivalente cadastrada. É só consulta.

Auditoria: `categoria.cadastrar`, `categoria.editar` (campos e nome anterior), `categoria.desativar`,
`categoria.reativar`.

### Integração

- **Cadastro e edição de livros** (`form-livro.php`): as opções já vinham de
  `livros_categorias_ativas()` (consulta ao banco). Categoria desativada some das opções e é
  recusada no servidor.
- **Filtros do painel** (`livros.php?categoria=`) e **site público** (`generos.php`,
  `catalogo.php?categoria=`, página do livro, resultado da pesquisa): já liam a tabela
  `categorias` e ignoram as desativadas. Nenhuma mudança foi necessária no site público.
- **Importação do acervo**: não cria categorias. O texto da coluna "Categoria" das planilhas
  continua indo para `registros_legados.categoria_original` (histórico), como antes.
- **Modo demonstrativo** (sem MySQL): a lista mostra as categorias fictícias só para consulta;
  qualquer envio é recusado sem gravar. As páginas de alunos mostram "Disponível só com o banco de dados".

---

## 3. Permissões

| Permissão | Administrador | Bibliotecária | Auxiliar | Somente consulta |
| --- | :-: | :-: | :-: | :-: |
| `alunos.ver` (lista e detalhes, dados pessoais) | ✓ | ✓ | ✓ | — |
| `alunos.editar` (cadastrar e corrigir) | ✓ | ✓ | — | — |
| `alunos.inativar` (inativar e reativar) | ✓ | ✓ | — | — |
| `categorias.gerenciar` | ✓ | ✓ | — | — |
| ver a lista de categorias (`painel.ver`) | ✓ | ✓ | ✓ | ✓ |

A auxiliar já via nomes e matrículas de alunos pelos empréstimos (`emprestimos.ver`), por isso
ganhou só a consulta. Tudo é conferido no servidor em cada página e em cada envio (CSRF em todos
os formulários); os botões escondidos são só conveniência.

---

## 4. Testes

`php admin/tests/alunos-categorias-teste.php` — 122 verificações em banco temporário com dados
fictícios: listagem, pesquisa com e sem acentos, paginação, ordenação, filtros, detalhes,
histórico de empréstimos, cadastro, edição, matrícula duplicada, e-mail, vínculos preservados,
permissões por perfil (pelas páginas), CSRF, XSS, auditoria, integração com a importação,
inativação; categorias: cadastro, edição, validação, duplicidades, contagem, bloqueio de
desativação, integração com o cadastro de livros, com o catálogo público e com a importação,
permissões, auditoria e o modo demonstrativo.

## 5. Atualização no XAMPP

1. Faça uma cópia de segurança (Sistema → Manutenção → Cópia de segurança).
2. Na pasta `C:\xampp\htdocs\BibliotecaDomPedro2`, atualize o código: `git pull` (branch `main`, depois do merge).
3. **Não há migração nova**: não é preciso rodar `php database/migrar.php` por causa desta etapa
   (rodar não faz mal: só aplica migrações pendentes).
4. Entre no painel como bibliotecária: aparecem **Gerenciamento → Alunos** e o cadastro real em **Categorias**.
