# Site público — guia

Área usada pelos alunos e visitantes. Não tem login nem contas de alunos: só consulta o catálogo e as informações da biblioteca. Nada no site público grava no banco.

## 1. Estrutura das páginas

| Endereço | Conteúdo |
|---|---|
| `index.php` | Página inicial: banner, boas-vindas, pesquisa, números do acervo, livros adicionados recentemente, categorias, horário e atalhos. |
| `biblioteca_php/catalogo.php` | Catálogo: pesquisa, filtros, ordenação e paginação (24 livros por página). |
| `biblioteca_php/livro.php?id=N` | Página de um livro: dados bibliográficos, sinopse, CDD aprovada, disponibilidade e livros relacionados. |
| `biblioteca_php/generos.php` | Categorias com a quantidade de livros (cada uma abre o catálogo filtrado). |
| `biblioteca_php/autores.php` | Autores com mais títulos (cada um abre o catálogo filtrado pelo autor). |
| `biblioteca_php/sobre.php` | Apresentação, espaço, objetivos, orientações, horário e contato. |
| `biblioteca_php/regras.php` | Como consultar, emprestar, devolver, conservar os livros e regras gerais. |
| `biblioteca_php/horarios.php`, `contato.php` | Endereços antigos: redirecionam para a página Sobre. O formulário de contato demonstrativo foi removido (não enviava nada). |

Código compartilhado:

- `includes/layout.php`: cabeçalho (menu lateral + barra de pesquisa), rodapé, cartão de livro, capa e selo de disponibilidade. Também envia os cabeçalhos de segurança.
- `includes/sidebar.php`: menu (Início, Catálogo, Categorias, Autores, Sobre a biblioteca, Regras e funcionamento). No computador começa aberto em telas largas; no celular abre por cima do conteúdo com fundo escurecido e fecha com Esc ou tocando fora.
- `includes/catalogo.php`: `catalogo()` e `site_conteudo()`, e a formatação segura dos textos institucionais.
- `src/Services/CatalogoService.php` e `src/Repositories/CatalogoRepository.php`: regras e consultas do catálogo (somente leitura).
- `src/Services/SiteConteudo.php` e `src/Repositories/SiteConfiguracaoRepository.php`: textos institucionais.
- `assets/css/style.css`, `assets/js/app.js`: estilo e comportamento (menu, indicador de carregamento). Sem bibliotecas externas.

O site não carrega nada de outros servidores: os ícones são SVG embutidos (`admin/includes/icones.php`) e as fontes são as do sistema. As imagens do banner e do emblema têm versões leves (`banner.jpg`, `banner-celular.jpg`, `emblema-pequeno.png`); os PNG originais continuam na pasta, mas não são carregados.

## 2. Como funciona a busca

- A pesquisa procura cada palavra digitada, em qualquer ordem, em título, subtítulo, autores, ISBN, assunto, palavras-chave e categorias. O campo "Pesquisar em" restringe a título, autor, ISBN ou assunto/categoria.
- **Com e sem acentos**: as comparações usam a collation `utf8mb4_unicode_ci` do MySQL/MariaDB, que ignora acentos, cedilha e maiúsculas ("principe" encontra "Príncipe", "coracao" encontra "Coração"). Não depende da extensão `intl` do PHP, então funciona no XAMPP padrão.
- ISBN pode ser digitado com ou sem hífens.
- Filtros: categoria, autor (pelos links do livro e da página Autores), ano de publicação (de/até) e disponibilidade física ("com exemplar disponível agora" ou "com exemplares identificados").
- Ordenação: título A–Z e Z–A, autor, ano (mais novos ou mais antigos) e adicionados recentemente.
- Paginação no servidor (`LIMIT/OFFSET`): só os 24 livros da página são carregados. Os filtros ficam na URL, então os links das páginas preservam a pesquisa.
- Todos os valores vêm por parâmetros preparados (PDO); filtros inválidos são ignorados e erros técnicos vão só para o log do servidor.
- A migração `080_site_publico.sql` cria índices para data de cadastro, ano e exemplares por situação.

## 3. Como é calculada a disponibilidade

A disponibilidade vem **somente** da tabela `exemplares` (unidades físicas com código BDP). A quantidade informada nas planilhas antigas (`registros_legados`) nunca é usada.

| Situação | O site mostra |
|---|---|
| Nenhum exemplar identificado (ou só baixados/extraviados) | "Exemplares ainda não identificados" — o livro está no catálogo, mas a biblioteca ainda não cadastrou as unidades físicas. |
| Pelo menos um exemplar `disponivel` | "N disponíveis" e, na página do livro, "N de T exemplares identificados". |
| Exemplares identificados, nenhum disponível | "Nenhum disponível no momento" (emprestados, aguardando liberação, em manutenção ou danificados). |

Na página do livro aparecem só os números (disponíveis, emprestados, em preparo/manutenção). Nunca aparecem quem está com o livro, datas, histórico ou códigos dos exemplares.

Como os números são lidos a cada visita, o site acompanha o painel imediatamente: empréstimo, devolução, cadastro de exemplar, edição do livro e arquivamento.

## 4. Como atualizar as informações institucionais

No painel: **Sistema → Site público** (administrador e bibliotecária). Campos:

- Identificação: nome da escola, mensagem de boas-vindas.
- Sobre a biblioteca: apresentação, espaço, objetivos, orientações de uso.
- Atendimento e contato: horário, localização, telefone, e-mail institucional, observação.
- Regras e funcionamento: regras gerais, informações extras de empréstimo e devolução, e a opção "Mostrar o prazo de empréstimo no site".
- Rodapé: créditos do projeto.

Formatação: linha em branco separa parágrafos; linhas que começam com "- " viram lista. Todo o texto é escapado (HTML digitado aparece como texto). Cada alteração fica registrada nas ações administrativas.

Enquanto um campo estiver vazio, o site mostra "Informação aguardando confirmação da escola". Nada é inventado.

**Prazo de empréstimo**: só aparece se `EMPRESTIMO_PRAZO_DIAS` estiver preenchido no `.env` **e** a opção "Mostrar o prazo de empréstimo no site" estiver marcada. Enquanto o prazo for o provisório (14 dias, `.env` vazio), o site diz apenas que a data de devolução é informada no balcão.

## 5. Como publicar novos livros no catálogo

- Todo livro **ativo** (não arquivado) aparece no catálogo, inclusive os ainda "aguardando conferência", porque os alunos podem procurá-los na estante. Cadastrar no painel (ou importar planilha) já publica.
- Editar o livro no painel atualiza o site na hora.
- **Arquivar** o livro (Acervo → Detalhes → Arquivar) o retira do site; a página dele passa a responder "Livro não encontrado". Restaurar o arquivamento o devolve.
- A CDD só aparece quando foi **aprovada** na Classificação CDD. A CDD antiga das planilhas e as propostas em revisão nunca aparecem.
- Observações internas, situação de validação, dados das planilhas e localização antiga nunca aparecem.

## 6. Como configurar imagens e capas

- Painel → Acervo → detalhes do livro → **Capa**: envie JPG, PNG ou WebP de até 2 MB (somente quem pode editar livros).
- O tipo é conferido pelo conteúdo do arquivo; a imagem é regravada (sem metadados) e limitada a 1200 px de altura; o nome é aleatório (`uploads/capas/livro-12-a1b2c3d4e5f6.jpg`).
- `uploads/capas/.htaccess` impede a execução de scripts. As capas enviadas não vão para o GitHub (`.gitignore`): faça backup da pasta `uploads/capas` junto com o banco.
- Sem capa, o site desenha uma capa padrão com o título e o autor. Capas nunca são associadas automaticamente nem buscadas em serviços externos.
- Endereços externos gravados no campo `capa` são ignorados pelo site, para que o navegador dos alunos não envie dados a terceiros.

## 7. Segurança e privacidade

- O site público só consulta tabelas bibliográficas (livros, autores, categorias, exemplares, CDD aprovada) e os textos institucionais. Não consulta alunos, empréstimos, administradores nem planilhas.
- Cabeçalhos: `Content-Security-Policy` (só recursos do próprio site), `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- `.htaccess` da raiz bloqueia `.env`, arquivos que começam com ponto, Composer, `.sql`, `.md`, logs, backups (`.bak`, `.old`, `.zip`...), planilhas e CSV, e responde 404 para `config/`, `src/`, `database/`, `storage/`, `includes/`, `docs/`, `vendor/`, `backups/`, `admin/includes/` e `admin/tests/`. Cada uma dessas pastas também tem o próprio `.htaccess`.
- `.gitignore` cobre `.env*` (menos `.env.example`), backups, dumps, logs, planilhas soltas na raiz e capas enviadas.
- **Atenção no XAMPP**: o `.htaccess` só funciona com `AllowOverride All` (padrão do XAMPP em `htdocs`). Não guarde backups do banco nem planilhas de alunos dentro de `htdocs`. O servidor embutido `php -S` (usado nos testes) ignora o `.htaccess`: use-o só no próprio computador.
- Em produção, use `APP_ENV=production` no `.env` para que erros nunca apareçam na tela.

## 8. Como executar os testes

```bash
php admin/tests/site-publico-teste.php
php admin/tests/site-publico-teste.php --manter   # mantém o banco temporário para conferir no navegador
```

O teste cria um banco temporário `bdp_teste_site_XXXX` (nunca usa o `DB_NAME` do `.env`), aplica as migrações, cria livros, exemplares, empréstimos e uma aluna **fictícios**, sobe o servidor embutido do PHP e confere: página inicial, listagem, pesquisa por título/autor/ISBN/assunto, com e sem acentos, filtros, ordenação, paginação, página de detalhes, livros sem capa, sem ISBN e sem exemplar, com exemplares disponíveis e todos emprestados, atualização após empréstimo/devolução/cadastro/edição/arquivamento, ocultação de arquivados, SQL injection, XSS, privacidade dos alunos, regras do `.htaccess`, `.gitignore`, painel "Site público" (permissões e CSRF), envio de capa, `php -l`, ausência de dependência do `intl` e, se Node + Playwright estiverem instalados, responsividade em 360, 768, 1366 e 1920 px. No fim, o banco temporário é apagado.

O usuário do `.env` precisa poder criar bancos (no XAMPP, o `root` pode). Os demais testes do painel continuam em `admin/tests/`.

## 9. O que depende de confirmação da escola

- Nome oficial da escola para o rodapé e a página Sobre.
- Apresentação, descrição do espaço, objetivos e orientações de uso.
- Horário de funcionamento, endereço/localização, telefone e e-mail institucional.
- Regulamento: regras gerais, prazo de empréstimo (`EMPRESTIMO_PRAZO_DIAS`), limite de livros por aluno, renovações. O site não mostra multas, punições nem prazos não aprovados.
- Créditos do projeto no rodapé.
- Uso do emblema atual (`assets/img/emblema.png`) como identidade visual oficial.
- Reservas ou pedidos de empréstimo on-line: **não** implementados (fluxo ainda não definido).
