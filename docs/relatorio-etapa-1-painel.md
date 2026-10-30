# Relatório — Finalização do Painel Administrativo (Etapa 1)

Data: 30/10/2026. Trabalho feito sobre a `main` com os PRs #1 a #10 mesclados. Nenhuma migração nova: o banco existente é usado como está.

## 1. O que já existia e funcionava

- Login com `password_hash`, bloqueio após 5 tentativas, logout por POST com CSRF, contas demonstrativas só sem banco.
- Dashboard lendo o MySQL (títulos, exemplares, disponíveis, emprestados, atrasos, gráfico de empréstimos, categorias, atividades).
- Detalhes do livro e do exemplar lendo o MySQL, com a quantidade da planilha separada dos exemplares físicos.
- Classificação CDD completa (pesquisa, sugestão, aprovação, histórico, relatórios).
- Importação de planilhas com revisão, ISBNs inválidos em lote e registros legados imutáveis.
- Menu lateral recolhível com tooltips, menu móvel, cabeçalho com pesquisa e notificações calculadas do banco.

## 2. O que estava incompleto ou com problema (e foi corrigido)

| Problema encontrado | Correção |
|---|---|
| A lista de livros enviava **todos os 5.485 livros** ao navegador e filtrava/paginava em JavaScript. | Pesquisa, filtros, ordenação e paginação agora são feitos no servidor (`LivroRepository::listar`); só a página atual (25, 50 ou 100 linhas) é enviada. |
| Cadastrar e editar livros era **simulado** (nada era gravado). | Cadastro e edição gravam no banco, com validação no servidor, mensagens de sucesso/erro e registro em `acoes_administrativas`. |
| "Arquivar cadastro" era só visual. | Nova tela de arquivamento com verificação de vínculos, motivo obrigatório, confirmação, registro da ação e restauração. |
| O formulário exigia **categoria**, mas os livros importados não têm categoria (a planilha não tem esse dado). | Categoria passou a ser opcional; foi incluído o campo **Assunto**. |
| As páginas de cadastro/edição/detalhes também despejavam o acervo inteiro em JSON no navegador. | Com banco configurado, só vão os nomes de autores (para a sugestão do formulário). |
| Permissões de livros e contas não existiam (qualquer perfil "editaria", se gravasse). | Novas permissões `livros.editar`, `livros.arquivar` e `usuarios.gerenciar`, conferidas no servidor. |
| Contas só podiam ser criadas pelo terminal. | Tela de administradores e página "Meu perfil". |
| Dashboard sem empréstimos ativos, CDD pendente, pendências de importação e inventários; botão "Novo empréstimo" levava a uma página inexistente; exemplares de livros arquivados entravam na contagem. | Indicadores novos, lista de empréstimos recentes, alertas de importação/inventário, atalhos conforme o perfil, contagens só do acervo ativo. |
| "Meu perfil" e "Configurações" no menu da conta levavam a "Em desenvolvimento". | "Meu perfil" agora funciona; "Configurações" saiu do menu da conta (continua como "Em breve" no menu lateral). Para administradores, o menu da conta mostra "Administradores". |
| Sessão não expirava por inatividade; redefinir senha não derrubava sessões abertas. | Expira após 2 horas sem uso; a sessão guarda uma marca do hash da senha e cai quando a senha muda. |
| Em produção, erros do PHP poderiam aparecer na tela. | `display_errors` desligado quando `APP_ENV=production`; páginas do painel enviam `X-Frame-Options`, `nosniff`, `Referrer-Policy` e `Cache-Control: no-store`. |

## 3. O que foi implementado

**Livros**
- Listagem com título, subtítulo, autores, ISBN, editora/ano/edição, CDD (aprovada, proposta ou antiga da planilha), situação da CDD, exemplares físicos (e disponíveis) e ações.
- Pesquisa por título, subtítulo, autor, editora e ISBN, com várias palavras. Acentos e maiúsculas são ignorados pela collation do banco (`utf8mb4_unicode_ci`): "acao" encontra "Ação" também no MariaDB do XAMPP, **sem depender do `intl`**.
- Filtros: categoria (inclusive "sem categoria"), situação da CDD (inclusive "aguardando reclassificação"), situação do cadastro e origem. Ordenação por título, autor e data de cadastro. Botão "Limpar filtros", total de resultados, indicação de carregamento, estado vazio.
- Cadastro: título (obrigatório), subtítulo, autores, ISBN (validado pelo dígito de controle; nunca corrigido automaticamente), editora, ano, edição, idioma, categoria, assunto, CDD se conhecida (+ fonte), observações, situação. A CDD informada entra como **sugestão** na fila da Classificação CDD; nunca como aprovada. ISBN repetido impede o cadastro; título repetido pede confirmação ("é outra edição").
- Edição com controle de edição simultânea (se outra pessoa salvou antes, nada é sobrescrito) e registro de cada campo alterado (antes/depois).
- Aba "Origem da importação" nos detalhes: os valores originais de cada linha da planilha, que não mudam com a edição.
- Arquivamento: mostra exemplares, empréstimos, registros de planilha, linhas de importação, CDD e conferências ligados ao livro; recusa quando há exemplares em uso ou empréstimo aberto; exige motivo e confirmação; pode ser desfeito em "Ver arquivados". Não há exclusão física nem exclusão em massa.

**Administradores** (somente perfil Administrador)
- Lista com nome, e-mail, perfil, situação, data de cadastro e último acesso; pesquisa, filtros, ordenação e paginação.
- Cadastro com senha inicial + confirmação (mínimo 10 caracteres, letras e números), e-mail único.
- Edição de nome, e-mail, perfil e situação; redefinição de senha.
- Proteções: o último administrador ativo não pode ser desativado nem rebaixado; ninguém altera o próprio perfil nem desativa a própria conta.
- "Meu perfil": qualquer usuário troca a própria senha (exige a senha atual).

**Perfis** (usando os 4 perfis já existentes)

| | Administrador | Bibliotecária | Auxiliar | Consulta |
|---|:-:|:-:|:-:|:-:|
| Ver acervo, CDD, importações | ✓ | ✓ | ✓ | ✓ |
| Cadastrar/editar livros | ✓ | ✓ | ✓ | |
| Arquivar/restaurar livros | ✓ | ✓ | | |
| Aprovar CDD, executar importação | ✓ | ✓ | | |
| Gerenciar contas | ✓ | | | |

Empréstimos e devoluções ainda não têm telas (próxima etapa); quando tiverem, a bibliotecária terá essa permissão.

## 4. Arquivos

**Criados**
- `src/Repositories/LivroRepository.php` — listagem, busca, duplicidades, cadastro, edição, vínculos, arquivamento.
- `src/Repositories/AdministradorRepository.php` — contas de acesso e regra do último administrador.
- `admin/includes/livros.php`, `admin/includes/administradores.php`, `admin/includes/componentes.php` (avisos, paginação, ordenação).
- `admin/arquivar-livro.php`, `admin/administradores.php`, `admin/administrador.php`, `admin/perfil.php`.
- `admin/tests/painel-teste.php`, `docs/relatorio-etapa-1-painel.md`.

**Modificados**
- `admin/livros.php`, `admin/cadastrar-livro.php`, `admin/editar-livro.php`, `admin/detalhes-livro.php`, `admin/index.php`.
- `admin/includes/auth.php` (permissões, sessão, senha, `registrar_acao` movida de `importacao.php`), `importacao.php`, `acervo.php`, `form-livro.php`, `dashboard-dados.php`, `header.php`, `sidebar.php`, `menu.php`, `layout.php`, `icones.php`.
- `admin/assets/js/acervo.js` (envio real do formulário), `admin/assets/js/admin.js`, `admin/assets/css/acervo.css`.
- `config/app.php`, `admin/tests/isbn-lote-teste.php` (sessão de teste com a marca da senha), `README.md`.

## 5. Migrações

Nenhuma. Tudo usa as tabelas existentes (`livros`, `autores`, `livro_autores`, `livro_categorias`, `administradores`, `acoes_administrativas`, `classificacoes_cdd`, `registros_legados`...). Não foi criado índice: com ~5.500 livros a pesquisa leva de 5 a 35 ms.

## 6. Testes realizados

Todos em bancos temporários criados e apagados pelos próprios testes, em MariaDB 10.11 e PHP 8.3 (Linux). **Nenhum teste tocou o banco real.**

- `php admin/tests/painel-teste.php` — **89 de 89** (3.000 livros fictícios só no banco temporário): pesquisa com/sem acento, filtros, ordenação, paginação, validação de ISBN, cadastro, edição, edição simultânea, preservação dos dados da planilha, arquivamento bloqueado/permitido, restauração, contas, último administrador, senhas, e pelas páginas reais (servidor PHP): login/logout, senha errada, conta desativada, sessão derrubada após redefinir senha, restrições de bibliotecária/auxiliar/consulta (403 também enviando o formulário direto), CSRF ausente (419), XSS na pesquisa e no título, SQL injection na pesquisa.
- O mesmo teste rodou **sem as extensões `intl` e `iconv`** (como no XAMPP): 89 de 89.
- Testes anteriores continuam passando: CDD 48/48, importação 43/43, ISBN em lote 54/54.
- Navegação manual (Chromium) com o acervo real do CSV importado num banco temporário (5.485 livros, 57 pendências): dashboard, listagem, cadastro, detalhes/origem, administradores e perfil, em 1366 px e 390 px.

**Não testado:** XAMPP no Windows de verdade (só simulado sem `intl`/`iconv`), MySQL 8 (só MariaDB) e os gráficos do dashboard (o Chart.js vem de CDN e o ambiente de teste não tem internet).

## 7. Limitações e pendências

- Exemplares (adicionar, editar, baixa) e categorias continuam simulados: pertencem ao inventário, que é a próxima etapa. Na tela do livro, o botão "Adicionar exemplares" só aparece no modo demonstrativo.
- Não há upload de capa (a seção foi escondida quando há banco, para não fingir que salva).
- Empréstimos, devoluções, atrasos, alunos, relatórios, atividades e configurações continuam "Em breve".
- A pesquisa trata cada palavra separadamente (ex.: "Autor 49" também encontra títulos com "49").
- A página de detalhes do livro ainda carrega o acervo inteiro no servidor para montar a tela (rápido com 5.500 livros, ~0,4 s; pode ser otimizada depois).
- A primeira vez após atualizar, todos precisarão entrar de novo (a sessão passou a guardar a marca da senha).

## 8. Como atualizar o projeto local (VS Code + XAMPP)

1. No VS Code, abra `C:\xampp\htdocs\BibliotecaDomPedro2`, menu **Terminal → Novo terminal**.
2. Depois de mesclar o PR no GitHub:
   ```
   git checkout main
   git pull origin main
   ```
   (Para testar antes de mesclar: `git fetch origin` e `git checkout claude/project-thread-j2a3gg`.)
3. Não há migração nem `composer install` novo. Não rode `importar-acervo.php` nem `--apagar-demo`.
4. Abra `http://localhost/BibliotecaDomPedro2/admin/` e entre de novo.
5. Opcional, para rodar os testes (criam e apagam um banco temporário, exigem usuário que possa criar bancos, como o `root` do XAMPP):
   ```
   C:\xampp\php\php.exe admin\tests\painel-teste.php
   ```
