# Empréstimos, devoluções e atrasos

Módulo de circulação do painel administrativo (menu **Circulação**). Funciona com os alunos da tabela `alunos` (importados pela página *Importar Alunos*) e com os exemplares físicos da tabela `exemplares` (código BDP). Não há conta, login nem senha para alunos.

## Páginas

| Página | Para que serve | Permissão |
| --- | --- | --- |
| `emprestimos.php` | Abas Ativos, Atrasados, Devolvidos e Histórico completo; pesquisa por aluno, matrícula, e-mail, título ou código do exemplar; período de retirada; paginação no servidor | `emprestimos.ver` |
| `novo-emprestimo.php` | Registrar empréstimo em 3 passos: aluno → exemplar → resumo e confirmação | `emprestimos.registrar` |
| `emprestimo.php?id=` | Dados do empréstimo, renovações e movimentações | `emprestimos.ver` |
| `devolver-emprestimo.php?id=` | Confirmação e registro da devolução | `emprestimos.registrar` |
| `renovar-emprestimo.php?id=` | Renovação | `emprestimos.registrar` |
| `devolucoes.php` | Ler/digitar o código do exemplar e ir direto para a confirmação da devolução; últimas devoluções | `emprestimos.ver` |
| `atrasos.php` | Empréstimos atrasados com os dias de atraso | `emprestimos.ver` |
| `historico-aluno.php?id=` | Ativos, anteriores, devoluções, atrasos e renovações do aluno (também de alunos inativos) | `emprestimos.ver` |
| `historico-exemplar.php?id=` ou `?codigo=` | Situação atual, empréstimos e movimentações do exemplar | `emprestimos.ver` |

Perfis: **administrador** e **bibliotecária** têm tudo, inclusive `emprestimos.prazo` (escolher outra data de devolução e informar a data real de uma devolução registrada depois). **Auxiliar** registra empréstimos, devoluções e renovações só com o prazo padrão. **Consulta** não vê a circulação, porque ela tem dados pessoais dos alunos; no dashboard, esse perfil vê só os totais, sem nomes.

## Regras

- Só são emprestados exemplares com situação **disponível**. Exemplares *aguardando identificação* (ainda não conferidos no inventário), em manutenção, extraviados ou baixados aparecem com o motivo, sem botão de escolha.
- Livros importados das planilhas sem exemplar físico aparecem como "Nenhum exemplar físico identificado"; nenhum exemplar é criado pelo módulo.
- Só alunos cadastrados e **ativos**. A pesquisa por nome nunca escolhe sozinha: homônimos aparecem lado a lado, com matrícula e turma.
- Cada operação roda em transação, com a linha do exemplar ou do empréstimo bloqueada (`SELECT ... FOR UPDATE`). Dois pedidos simultâneos para o mesmo exemplar resultam em um único empréstimo; o índice único `exemplar_em_aberto` (migração 010) é a segunda barreira.
- Empréstimo, situação do exemplar, movimentação e registro de ação são gravados juntos: se qualquer parte falhar, nada fica gravado.
- A devolução não apaga nada: preenche `data_devolucao` e `recebido_por`, libera o exemplar (ou o manda para manutenção, se marcado) e registra a movimentação. Um empréstimo devolvido não pode ser devolvido de novo nem renovado (garantido também por gatilho no banco).
- Atraso = empréstimo em aberto com `data_prevista` anterior a hoje, calculado na consulta com a data da escola (`APP_TIMEZONE`). Não há multas nem avisos por e-mail.
- Registros de ação e movimentações guardam só ids, código do exemplar e título do livro, nunca nome, matrícula ou e-mail do aluno. Erros técnicos vão para o log só com o tipo do erro.

## Configuração (`.env`)

A escola ainda não aprovou um regulamento. Enquanto as variáveis estiverem vazias, valem os padrões **provisórios**, e as telas avisam que o prazo é provisório.

| Variável | Padrão provisório | Efeito |
| --- | --- | --- |
| `EMPRESTIMO_PRAZO_DIAS` | 14 | Prazo do empréstimo |
| `EMPRESTIMO_RENOVACAO_DIAS` | igual ao prazo | Dias acrescentados em cada renovação (a partir de hoje ou do prazo atual, o que for maior) |
| `EMPRESTIMO_MAX_RENOVACOES` | sem limite (o banco aceita até 10) | Limite de renovações por empréstimo |
| `EMPRESTIMO_RENOVAR_ATRASADO` | `true` | `false` impede renovar empréstimo atrasado |
| `EMPRESTIMO_PRAZO_MAXIMO_DIAS` | 365 | Maior prazo aceito ao escolher uma data manualmente |
| `APP_TIMEZONE` | `America/Sao_Paulo` | Fuso da escola (datas, prazos, atrasos e conexões MySQL) |

## Banco de dados

A migração `060_circulacao.sql` só acrescenta estruturas (nenhum dado é alterado):

- tabela `emprestimo_renovacoes` (prazo anterior, novo prazo, responsável, data), sem alteração nem exclusão;
- valor `renovacao` em `movimentacoes.tipo`;
- gatilho `emprestimos_preservar_historico`: empréstimo devolvido não muda; aluno, exemplar e data de retirada nunca mudam;
- índice `emprestimos_data_idx` para a listagem por data.

As tabelas `alunos`, `emprestimos`, `exemplares` e `movimentacoes` já existiam (migrações 001 e 010) e foram reaproveitadas.

## Testar com dados fictícios

Automático (cria e apaga um banco temporário, nunca usa o banco do `.env`):

```bash
php admin/tests/emprestimos-teste.php
```

No XAMPP: `C:\xampp\php\php.exe admin\tests\emprestimos-teste.php`, dentro da pasta do projeto (o usuário do `.env` precisa poder criar bancos; o `root` do XAMPP pode).

Manual, em um banco **de teste** (nunca no `biblioteca_dom_pedro`):

1. Crie um banco vazio, por exemplo `biblioteca_teste`, aponte o `DB_NAME` de um `.env` de teste para ele e rode `php database/migrar.php`.
2. Crie uma conta: `php database/criar-admin.php "Bibliotecária Teste" bib@teste.local bibliotecaria`.
3. Cadastre alunos fictícios pela página *Importar Alunos* com uma planilha inventada (ex.: matrícula `TESTE-001`, nome `Aluno Fictício`).
4. Gere exemplares fictícios para um livro de teste, no MySQL do banco de teste: `CALL gerar_exemplares(<id do livro>, 3, NULL, 'bom', 'disponivel');`.
5. Em **Circulação → Empréstimos**, use *Novo empréstimo*, registre a devolução, renove e confira os históricos e o dashboard.
