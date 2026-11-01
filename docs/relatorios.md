# Relatórios administrativos

A página **Relatórios** do painel (`/admin/relatorios.php`, item **Relatórios** no menu lateral) reúne estatísticas e exportações de todos os módulos. Ela só **lê** o banco: abrir um relatório ou exportar um arquivo não altera livros, exemplares, alunos, empréstimos, classificações, inventários nem o histórico administrativo. A única coisa gravada é o registro de cada exportação em `acoes_administrativas`.

Os relatórios funcionam apenas com o MySQL/MariaDB configurado. No modo demonstração (sem banco), a página mostra um aviso.

## Seções

| Seção | Endereço | O que mostra |
|---|---|---|
| Visão geral | `relatorios.php` | Números principais de cada módulo e atalhos para as seções. |
| Acervo | `?secao=acervo` | Registros bibliográficos, com e sem ISBN, dados incompletos, possíveis duplicidades, categorias, assuntos, autores, livros cadastrados por período e a quantidade histórica das planilhas em coluna separada. |
| Exemplares físicos | `?secao=exemplares` | Somente exemplares com código BDP: situação, conservação, setor, estante e prateleira, exemplares sem localização e nunca conferidos. |
| Empréstimos e devoluções | `?secao=emprestimos` | Empréstimos por período, devoluções, renovações, livros e categorias mais emprestados, evolução no tempo. |
| Atrasos | `?secao=atrasos` | Atrasos atuais por faixa (1 a 7, 8 a 15, mais de 15 dias), por mês de vencimento e devoluções feitas após o prazo. |
| Alunos | `?secao=alunos` | Alunos por turma e turno, ativos e inativos, com empréstimo ativo e com atraso. |
| Inventários | `?secao=inventarios` | Progresso de cada inventário, conferidos, não conferidos, divergências e pendências. |
| Classificação CDD | `?secao=cdd` | CDD aprovadas, pendentes, sugeridas, em revisão, em conflito, CDD antiga das planilhas e distribuição pelas classes 0 a 9. |
| Atividades administrativas | `?secao=atividades` | Ações registradas no painel, por módulo, responsável e período. |

## Como os números são calculados

Cada seção tem um quadro "Como os números são calculados". As regras principais:

- **Registros bibliográficos e exemplares são contas diferentes.** Exemplares são só as linhas reais da tabela `exemplares` (com código BDP). A quantidade informada nas planilhas antigas aparece como "quantidade histórica", em coluna própria, e nunca é somada aos exemplares nem tratada como quantidade física confirmada.
- **Livros arquivados** ficam fora das contas do acervo e dos exemplares.
- **Possíveis duplicidades** são registros com o mesmo título e o mesmo primeiro autor. Eles aparecem só como aviso para revisão; nada é juntado e nenhum registro vira exemplar.
- **"Sem exemplar físico"** conta registros que não têm nenhum exemplar ativo (baixados e extraviados não contam).
- **Exemplares sem localização** são os que estão no acervo sem setor, estante ou prateleira cadastrados. "Nunca conferidos" são os que ainda não têm nenhuma conferência registrada no cadastro do exemplar. Nenhum exemplar é marcado como extraviado só por não ter sido conferido.
- **Cada empréstimo conta uma vez**, mesmo que tenha sido renovado. As renovações aparecem em um indicador próprio, pela data da renovação.
- **Datas usadas no período:** empréstimos pela data de retirada; devoluções pela data de devolução; renovações pela data da renovação; livros cadastrados pela data de cadastro no sistema (que não é a data de aquisição física, e o relatório diz isso).
- **"Ativos agora", "atrasados agora" e os atrasos por faixa** mostram a situação de hoje, como no dashboard, e não dependem do período.
- **Atrasos** são contados em dias, sem multa. O sistema não calcula valores financeiros.
- **CDD aprovada** é só a que tem status "aprovada". A CDD antiga das planilhas aparece como "CDD antiga (não aprovada)" e nunca como aprovada. Os relatórios não alteram classificações.
- **Inventários** usam as mesmas contas da página de cada inventário.
- **Dashboard e relatórios usam as mesmas consultas** para exemplares, empréstimos ativos, atrasados e devolvidos, então os números batem.

## Filtros de período

As seções Acervo, Empréstimos, Atrasos e Atividades têm o filtro de período:

- Últimos 7, 30 ou 90 dias (contando hoje), ano atual, todo o período ou período personalizado.
- As datas seguem o fuso da escola (`APP_TIMEZONE`, padrão `America/Sao_Paulo`).
- No período personalizado, a data inicial não pode ser maior que a final. Datas inválidas mostram um aviso e o relatório volta ao período padrão da seção; as datas nunca são trocadas de lugar sem avisar.
- O mesmo período vale para os indicadores, os gráficos, as tabelas e os arquivos exportados.

As outras seções mostram a situação atual. Os demais filtros (situação, livro, categoria, turma, localização, conservação, status da CDD, módulo, responsável etc.) aceitam apenas valores conhecidos; qualquer outro valor é ignorado.

## Gráficos

Os gráficos são gerados no próprio servidor (HTML e SVG), sem bibliotecas externas, e por isso funcionam sem internet e na impressão. Todo gráfico:

- usa apenas dados reais do banco (sem números de exemplo);
- tem legenda e mostra os valores;
- tem o link "Ver dados em tabela" com os mesmos números;
- mostra "Sem registros para exibir" quando não há dados;
- se ajusta à tela do celular.

Nenhum gráfico mostra nomes, matrículas ou e-mails de alunos.

## Exportação

Cada tabela tem os botões **CSV**, **Excel (XLSX)** e **PDF**. O arquivo respeita os filtros e o período da tela.

- **PDF:** página A4, com nome da biblioteca, título, data de geração, período, filtros, indicadores, gráficos (no relatório principal), tabela com cabeçalho repetido em cada página, numeração "Página X de Y" e rodapé "Documento interno — não publicar". Para não gerar arquivos enormes, o PDF traz até 2.000 linhas e avisa quando há mais; a lista completa sai no CSV ou no XLSX.
- **CSV:** separado por ponto e vírgula, em UTF-8 com BOM (abre com acentos no Excel). Códigos só com números (ISBN, matrícula) saem como texto para manter os zeros à esquerda.
- **XLSX:** gerado pelo próprio sistema, com cabeçalho, filtros e primeira linha fixa. ISBN e códigos BDP ficam como texto; números ficam como número. Precisa da extensão `zip` do PHP (já vem no XAMPP); sem ela, o arquivo sai em CSV.
- **Proteção contra fórmulas:** qualquer célula que comece com `=`, `+`, `-`, `@`, tabulação ou retorno recebe um apóstrofo na frente, para o Excel não executar nada.
- **Arquivos grandes** são gerados em partes (lotes de 1.000 linhas), sem carregar tudo na memória, até 100.000 linhas por arquivo.
- **Nomes descritivos**, por exemplo `relatorio-emprestimos-emprestimos-2026-01-01-a-2026-10-31.xlsx` (seção, tabela e período).
- Os arquivos são enviados direto para o navegador. Nada é gravado em pastas públicas e nada é enviado para serviços externos. O XLSX usa um arquivo temporário do sistema, apagado logo após o envio.

## Permissões

As permissões são verificadas no servidor (abrir a página, trocar a seção na URL ou chamar a exportação direto). Os perfis existentes foram mantidos:

| Permissão | Administrador | Bibliotecária | Auxiliar | Consulta |
|---|:-:|:-:|:-:|:-:|
| Ver relatórios (`relatorios.ver`) | sim | sim | sim | sim |
| Exportar (`relatorios.exportar`) | sim | sim | sim | sim |
| Seção Alunos e nomes de alunos nas listas (`relatorios.alunos`) | sim | sim | sim | não |
| Exportar arquivos com dados de alunos (`relatorios.exportar_pessoais`) | sim | sim | não | não |
| Atividades administrativas (`relatorios.atividades`) | sim | sim | não | não |

- Nome, matrícula e turma de alunos aparecem nas listas de empréstimos e atrasos só para quem tem `relatorios.alunos` e também pode ver a circulação (`emprestimos.ver`). E-mail de aluno nunca aparece.
- Sem `relatorios.exportar_pessoais`, as colunas de alunos são retiradas do arquivo; a lista "alunos com empréstimo ativo" não pode ser exportada (403).
- O perfil Consulta vê apenas números agregados; o filtro por turma é ignorado para ele.
- Ações sobre contas de acesso (cadastro e edição de administradores) só aparecem em Atividades para quem gerencia contas. Detalhes como e-mail, IP e senha nunca são mostrados.
- Não existem rankings de alunos por número de empréstimos ou atrasos.

## Registro das exportações

Cada exportação grava a ação "Relatório exportado" no histórico administrativo, com a tabela, o formato, o período, os filtros, o número de linhas e se o arquivo tinha dados de alunos. O histórico existente nunca é alterado nem apagado.

## Segurança

- Consultas com parâmetros (sem SQL montado com texto do usuário) e filtros só com valores conhecidos.
- Todo texto mostrado na tela passa por escape (proteção contra XSS).
- Tabela ou formato inexistente: 404. Sem permissão: 403. Período inválido na exportação: 422. Erros de banco mostram uma mensagem genérica, sem detalhes internos.

## Banco de dados

Esta etapa **não tem migração**: nenhuma tabela ou coluna foi criada ou alterada. As contas usam as tabelas e os índices que já existem (empréstimos, exemplares, inventários, CDD, ações administrativas). Não é preciso rodar nada no banco para usar os relatórios.

## Testes

```bash
php admin/tests/relatorios-teste.php
```

O teste cria um banco temporário (`bdp_teste_relatorios_*`), aplica as migrações, cadastra só dados fictícios, confere as contas de cada seção, os períodos, os gráficos, os arquivos PDF/CSV/XLSX (acentos, zeros à esquerda, fórmulas), o desempenho com 5.000 registros a mais, as permissões e a privacidade pelo servidor embutido do PHP, e verifica que nenhum dado mudou. No fim o banco temporário é apagado. O banco do `.env` não é usado.

## Limitações conhecidas

- A data de aquisição física dos exemplares não existe no cadastro; o relatório de cadastros usa a data de cadastro no sistema e diz isso.
- O PDF usa a fonte Helvetica do próprio PDF (sem fontes incorporadas): caracteres fora do português ocidental aparecem como "?".
- O PDF lista até 2.000 linhas e as planilhas até 100.000 linhas por arquivo.
- O XLSX precisa da extensão `zip` do PHP; sem ela, a exportação sai em CSV.
