# Relatório — Etapa 10: Central de Ajuda do painel

**Data:** 03/11/2026 · **Branch:** `claude/project-thread-428puj` · **Situação:** aguardando revisão (sem merge, sem publicação).

## 1. Resumo

O painel ganhou a **Central de Ajuda** (Configurações → **Tutorial do Sistema**), com **16 módulos e 99 tutoriais**
passo a passo, pesquisa local com e sem acentos e o botão discreto **Como usar esta página?** nas páginas do painel.
Todo o conteúdo foi escrito a partir das telas reais: os nomes de menus, campos, botões e mensagens citados são
conferidos automaticamente contra o código-fonte (672 nomes) pelo teste `admin/tests/ajuda-teste.php`.

## 2. Números

| Item | Quantidade |
|---|---|
| Módulos | 16 |
| Tutoriais | 99 (incluindo 15 perguntas frequentes) |
| Capturas de tela no conteúdo | 26 (dados fictícios, PNG otimizado, 9–68 KB cada) |
| Páginas com "Como usar esta página?" | 48 páginas mapeadas |
| Nomes citados conferidos no código | 672 |
| Verificações do teste da ajuda | 132 (todas passando) |

### Tutoriais por módulo

| # | Módulo | Tutoriais | Assuntos |
|---|---|---|---|
| 1 | Primeiros passos | 10 | o que é o sistema, entrar, sair, menu lateral, página atual e Dashboard, filtros e tabelas, mensagens, botões importantes, permissões, usar a ajuda |
| 2 | Dashboard | 4 | visão geral, gráficos, pendências e atrasos, atalhos |
| 3 | Livros | 11 | consultar, pesquisar, filtros, cadastrar, editar, detalhes, dados incompletos, duplicados, sem ISBN, livro × exemplar, arquivar |
| 4 | Exemplares | 8 | o que é, cadastrar, **código BDP-000001**, consultar, localizações, atualizar, disponibilidade, extravio/baixa/pendências |
| 5 | Empréstimos | 5 | **registrar (12 etapas)**, aluno não aparece, exemplar indisponível, regras e limites, consultar |
| 6 | Devoluções e renovações | 3 | devolução, renovação, atrasos (sem multas) |
| 7 | Alunos | 5 | pesquisar, histórico, atualizar via importação, erros de identificação, privacidade |
| 8 | Importação | 5 | preparar planilha, importar alunos, importar acervo, revisar/duplicidades, ISBN inválido |
| 9 | Inventário | 5 | para que serve, criar/iniciar, conferir, divergências e pendências, concluir e relatório |
| 10 | Etiquetas | 2 | gerar (só texto), modelos e impressão A4 |
| 11 | CDD | 5 | sugerida × aprovada, painel, registrar/sugerir, aprovar, verificar/histórico/relatórios |
| 12 | Relatórios | 4 | acessar, filtros e períodos, interpretar indicadores, exportar CSV/XLSX/PDF |
| 13 | Administradores e permissões | 5 | perfis, consultar contas, cadastrar/editar/desativar, trocar senha, Categorias (demonstrativa) |
| 14 | Configurações | 6 | como funcionam, identificação e alunos, regras de empréstimo, segurança, site em manutenção, textos do site |
| 15 | Backup e manutenção | 6 | para que serve, gerar, guardar, conferir, diagnóstico e limpeza, restauração (tarefa técnica) |
| 16 | Solução de problemas | 15 | as 15 perguntas pedidas |

Cada tutorial segue o modelo **A Título · B Objetivo · C Quando utilizar · D Pré-requisitos · E Passo a passo ·
F Exemplo prático · G Resultado esperado · H Problemas frequentes · I Cuidados importantes · J Próximos passos**.

## 3. Como acessar

- Menu lateral (expandido): **Configurações → Tutorial do Sistema**.
- Menu do seu nome (canto superior direito): **Tutorial do Sistema** — para perfis que não veem Configurações
  e para o menu recolhido.
- Botão **Tutorial do Sistema** no topo da página Configurações.
- Botão **Como usar esta página?** ao lado do caminho de navegação das páginas: abre o tutorial da página e
  mostra **Voltar para …** para retornar.

Endereço: `admin/ajuda.php` (exige login; os quatro perfis leem).

## 4. Como atualizar o conteúdo

Ver `admin/includes/ajuda/LEIA-ME.md`. Resumo: cada módulo é um arquivo PHP em `admin/includes/ajuda/tutoriais/`,
com o formato documentado; edite, rode `php admin/tests/ajuda-teste.php` e faça commit. Não há editor no painel,
tabela nova nem migração.

## 5. Arquivos

**Novos**

- `admin/ajuda.php` — página da Central (início, módulo, tutorial, pesquisa, 404).
- `admin/includes/ajuda.php` — carregamento, pesquisa, formatação segura, "voltar" validado, ajuda contextual.
- `admin/includes/ajuda/modulos.php`, `admin/includes/ajuda/tutoriais/01…16-*.php`, `admin/includes/ajuda/LEIA-ME.md`.
- `admin/assets/css/ajuda.css`, `admin/assets/js/ajuda.js` (impressão), `admin/assets/ajuda/*.png` (26 capturas).
- `admin/tests/ajuda-teste.php`, `docs/relatorio-etapa-10-ajuda.md`.

**Alterados**

- `admin/includes/menu.php` — item "Tutorial do Sistema" sob Configurações (sem permissão própria: todos leem).
- `admin/includes/header.php` — atalho no menu do perfil.
- `admin/includes/layout.php` — carrega `ajuda.php`; versão do CSS.
- `admin/includes/acervo.php` — o caminho de navegação recebe o botão contextual.
- `admin/index.php`, `admin/site-publico.php` — botão contextual (páginas sem caminho de navegação).
- `admin/configuracoes.php` — botão "Tutorial do Sistema".
- `admin/includes/icones.php` — ícones circle-help, life-buoy, list-ordered.
- `admin/assets/css/admin.css` — estilo do botão contextual.
- `admin/tests/auditoria-seguranca-teste.php` — a auditoria exige regra de acesso para cada página; `ajuda.php` entrou como "todos os perfis".
- `docs/manual-bibliotecaria.md` — aponta para a Central e corrige 2 divergências (item 7).
- `docs/testes.md` — novo comando de teste.

## 6. Segurança

- Exige login (`auth_exigir('painel.ver')`); sem login, redireciona para o login.
- A leitura não concede permissão: tutoriais de funções restritas aparecem para todos com o selo
  **Seu perfil não tem esta função** e o quadro de permissão; links para páginas só aparecem para quem pode abri-las;
  as páginas continuam conferindo a permissão no servidor (testado: consulta segue com 403 em Novo empréstimo,
  auxiliar segue com 403 em Manutenção).
- "Voltar" aceita só `arquivo.php?consulta` de páginas do menu do painel: sem domínio, esquema, `//`, `../`,
  `javascript:` ou quebra de linha (redirecionamento aberto impossível). Testado com 14 entradas maliciosas.
- Conteúdo escapado antes da marcação simples; pesquisa com HTML é escapada.
- Nenhuma credencial, senha, nome de banco ou dado real; os e-mails citados são `@escola.exemplo`/`@teste.local`.
- Nenhum tutorial orienta apagar registros, alterar o banco ou desativar a segurança. Não há restauração pelo painel.
- Funciona sem internet: nenhum script, estilo ou imagem externa (testado em todas as 99 páginas × 4 perfis).

## 7. Divergências encontradas (documentadas como estão)

1. **Alunos** no menu aparece como **Em breve**: não existe página de consulta/edição de alunos. A ajuda ensina a
   pesquisar pelo Novo empréstimo, pela lista de Empréstimos e pelo Histórico do aluno.
2. Não há tela para **ativar/inativar aluno** nem para editar um aluno; atualização só pela importação
   (que não reativa inativos).
3. **Categorias** mostra sempre "Dados demonstrativos" (cadastros simulados), mesmo com o MySQL. Há um tutorial avisando.
4. **Sem recuperação de senha** pelo e-mail: um administrador redefine.
5. **Sem restauração** pelo painel (proposital); documentada como tarefa técnica.
6. O manual antigo citava **"Salvar e adicionar exemplares"** (só existe no modo demonstrativo; com MySQL é
   "Salvar e cadastrar outro") e o caminho **"Empréstimos → Devoluções"** (o real é o grupo Circulação → Devoluções).
   Corrigido em `docs/manual-bibliotecaria.md`.
7. Os campos de data aparecem no formato do navegador (nas capturas, o Chromium de teste mostrou mês/dia/ano).

## 8. Testes

| Teste | Resultado |
|---|---|
| `ajuda-teste.php` (novo) | 132 de 132 |
| `auditoria-seguranca-teste.php` | 108 de 108 |
| `painel-teste.php` | 89 de 89 |
| `fluxo-integracao-teste.php` | 66 de 66 |
| `emprestimos-teste.php` | 109 de 109 |
| `inventario-teste.php` | 139 de 139 |
| `cdd-teste.php` | 48 de 48 |
| `relatorios-teste.php` | 121 de 121 |
| `configuracoes-teste.php` | 96 de 96 |
| `site-publico-teste.php` | 119 de 119 |
| `codigos-etiquetas-teste.php` | 88 de 88 |
| `importacao-teste.php` | 43 de 43 |
| `isbn-lote-teste.php` | 54 de 54 |
| `alunos-importacao-teste.php` | 75 de 75 |
| `backup-restauracao-teste.php` | 34 de 34 |
| `desempenho-teste.php` | 32 de 32 |

Todos rodaram em bancos temporários (PHP 8.3.6, MariaDB 10.11); nenhum dado real foi alterado. O teste da ajuda
confere ainda que ler a ajuda e abrir as páginas não muda nenhuma tabela (checksum antes e depois).

Os 16 itens pedidos: acesso por perfil ✓, links e retorno ✓, pesquisa com/sem acento ✓, categorias/módulos ✓,
abrir e voltar de tutoriais ✓, relacionados ✓, ajuda contextual ✓, imagens com alt ✓, celular (390 px, sem rolagem
lateral) ✓, teclado (pesquisa e tutoriais alcançáveis com Tab, foco visível, perguntas expansíveis com Enter) ✓,
impressão (menu, topo e índice escondidos; perguntas abertas) ✓, sem internet ✓, PHP 8.2+ ✓, funcionalidades
existentes preservadas ✓, nomes citados conferidos ✓, nenhum dado real alterado ✓.

## 9. Limitações

- O conteúdo é editado em arquivos (pedido da etapa); não há editor no painel.
- Capturas feitas no ambiente de teste, com dados fictícios; se o visual mudar, refaça-as (o teste só confere que existem).
- A pesquisa é por palavras (início de palavra, singular/plural simples); não corrige erros de digitação.
- O teste de "nomes citados" confere o texto no código, não a posição na tela.

## 10. Confirmações

- ✅ **Dados preservados:** nenhuma tabela ou migração nova; nenhum dado real alterado.
- ✅ **Site público inalterado:** nenhum arquivo do site público foi modificado.
- ✅ **Regras de empréstimo e inventário inalteradas:** nenhum serviço ou regra foi tocado.
- ✅ **Segurança preservada:** login obrigatório, permissões conferidas no servidor, retorno validado, sem recursos externos.
- ✅ **Documentação de acordo com o sistema:** 672 nomes conferidos automaticamente.
- ✅ **Sem merge e sem publicação:** o PR fica aberto para a sua revisão.
