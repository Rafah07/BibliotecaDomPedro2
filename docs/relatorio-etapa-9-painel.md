# Etapa 9 — Modernização visual do painel administrativo

Data: 2026-11-02 · Branch `claude/project-thread-goek54` (base: `main`, já com o PR #21 do site público mergeado).

Modernização **conservadora**: a mesma estrutura, os mesmos agrupamentos, páginas e componentes do painel, com acabamento mais refinado. Nenhuma regra de negócio, consulta, permissão, migração ou rota foi alterada. O site público (`assets/`, `includes/` da raiz, `index.php`) não foi tocado.

## 1. Análise do painel atual

O painel já tinha uma base boa e foi reaproveitada:

- Layout compartilhado (`admin/includes/layout.php`, `header.php`, `sidebar.php`, `menu.php`) e um sistema de componentes com prefixo `adm-` (`admin.css` + `acervo.css`, que concentra campos, filtros, tabelas, paginação e avisos usados por quase todas as páginas).
- O menu lateral **já começava recolhido** e já guardava a escolha no navegador (`localStorage`, chave `adm-sidebar`). Esse comportamento foi mantido: sem preferência salva, o menu começa recolhido.

O que precisava de melhoria:

- Menu lateral branco, sem a identidade azul do site público; submenus (Classificação CDD, Localizações, Perfis e permissões, Manutenção) apareciam como ícones soltos no menu recolhido, sem relação visível com o módulo-pai.
- No celular, o menu fechado continuava na ordem do Tab (links invisíveis recebiam foco), o foco não ficava preso no painel aberto e escolher uma página não fechava o painel.
- Botão principal em azul vivo (#2457C5), destoando do azul institucional.
- Campo de pesquisa com contorno de foco duplo (o campo interno também mostrava contorno).
- Cartões com efeito de "levantar" ao passar o mouse; títulos e números sem a cor institucional.
- Na lista de empréstimos, a situação "Atrasado" ficava na coluna mais à direita, escondida pela coluna fixa de ações em telas de 1366 px.
- Na página Etiquetas, um botão com texto longo empurrava a página para o lado a 360 px.

## 2. Resumo visual

| Área | O que mudou | O que foi preservado |
| --- | --- | --- |
| Menu lateral | Fundo azul institucional (#123D73 → #0A2749), ícones e textos claros, página atual com fundo translúcido e marca dourada discreta (#D6AE62). Recolhido: coluna de 72 px só com ícones, nome do item ao passar o mouse **ou ao focar pelo teclado**, submenus escondidos e módulo-pai destacado. Expandido (256 px): ícones + nomes + submenus recuados com linha-guia. Animação de 0,2 s. | Mesmos grupos (Principal, Circulação, Gerenciamento, Sistema), itens, rotas, permissões e ordem. Preferência salva continua valendo. |
| Celular/tablet (≤ 900 px) | Painel sobreposto de até 280 px; fora da ordem do Tab quando fechado; foco vai para o painel ao abrir e fica preso nele; conteúdo inerte enquanto aberto; fecha por X, toque fora, Esc ou ao escolher uma página; foco volta ao botão do menu. | Botão ☰ no cabeçalho. |
| Cabeçalho | Altura 64 px, título da página em azul-escuro, separador entre ações e administrador conectado, avatar com aro dourado discreto. | Pesquisa (atalho "/"), notificações, menu do perfil, Sair (POST com CSRF). Nenhum dado novo de sessão no HTML. |
| Dashboard | Números e títulos em azul-escuro, cartões sem "salto" no hover, gráfico de barras e rosca na paleta institucional, alertas de atraso com fundo levemente avermelhado. | Mesmos indicadores, dados, consultas, atalhos e ordem. Chart.js local (sem dependência externa). |
| Tabelas | Cabeçalho mais legível (contraste maior), linhas um pouco mais altas, separadores mais suaves. Empréstimos: linha atrasada com marca vermelha à esquerda e data prevista em vermelho; colunas um pouco mais compactas. | Todas as colunas, ações, ordenação, paginação e o modo "cards" no celular. |
| Formulários | Bordas dos campos padronizadas, rótulos mais escuros, contorno de foco único e consistente, caixas de confirmação sensível (ex.: cópia de segurança) em fundo âmbar. | Todos os campos, nomes enviados ao PHP, validações e mensagens. |
| Botões | Principal em azul institucional, secundário com contorno discreto, foco visível. Em telas ≤ 420 px, textos longos quebram linha. "Apagar tentativas antigas" (Manutenção) agora em vermelho. | Todos os botões e confirmações existentes (`data-confirm`). |
| Abas, paginação, passos | Item ativo em azul institucional. | Comportamento. |
| Login | Logotipo com o mesmo azul. | Formulário. |

## 3. Alterações técnicas

- `admin/assets/css/admin.css`: tokens revisados (azul institucional, `--adm-navy-dark`, `--adm-gold`, `--adm-border-strong`, `--adm-focus-ring`, `--adm-duration`); menu lateral reescrito no mesmo HTML (regras de recolhido agora só ≥ 901 px, submenus, `is-parent-active`, nomes de grupo acessíveis a leitores de tela); painel do celular com `visibility` para sair da ordem do Tab; cabeçalho, botões, cartões, tabelas.
- `admin/assets/css/acervo.css`: campos, pesquisa (contorno único), abas, paginação, passos, confirmação sensível.
- `admin/assets/css/emprestimos.css`: destaque de atraso, abas em links, espaçamento da tabela.
- `admin/assets/css/importacao.css`, `configuracoes.css`, `classificacao.css`: ajustes de cor para o azul institucional.
- `admin/assets/js/admin.js`: controle do painel no celular (foco, `inert`, Tab preso, fechar ao navegar, `aria-expanded`/`aria-label` atualizados), tooltip do botão de expandir.
- `admin/assets/js/dashboard.js` e `admin/includes/dashboard-dados.php`: só cores dos gráficos.
- `admin/includes/sidebar.php`: classe `is-parent-active` no módulo-pai da página atual.
- `admin/includes/emprestimos.php`: classe `adm-row--alerta` na linha de empréstimo atrasado (usa a mesma função `emprestimos_situacao()` do selo).
- `admin/manutencao.php`: classe de perigo no botão de limpeza.
- `layout.php`, `login.php` e páginas: `theme-color` e versão dos arquivos CSS/JS (`?v=20261102e9`) para o navegador baixar o visual novo.

Nenhum componente novo duplicado: tudo usa as classes e variáveis que já existiam.

## 4. Validação

Ambiente: Linux, PHP 8.3.6, MariaDB 10.11, Chromium (Playwright), servidor embutido do PHP. Banco de visualização `bdp_visual` só com os dados demonstrativos do projeto e alunos/empréstimos fictícios; o banco principal não foi usado.

- **15 suítes automáticas**: todas passaram (painel, importação, ISBN em lote, importação de alunos, empréstimos, inventário, CDD, relatórios, configurações, site público, códigos/etiquetas, fluxo de integração, auditoria de segurança, backup/restauração, desempenho com `--livros=3000`). Elas cobrem login, permissões por perfil, cadastro de livros, empréstimos, devoluções, inventário, importações, relatórios, configurações e logout.
- **Teste do menu no navegador (29 verificações, todas ok)**: começa recolhido (72 px), botão visível, `aria-expanded`, submenus escondidos, módulo-pai destacado, nome por tooltip ao focar pelo teclado, expande para 256 px sem sobrepor o conteúdo, preferência salva após recarregar, recolher de novo; no celular: fechado fora do Tab, abre, foco no painel, conteúdo inerte, Tab preso, largura limitada, Esc/toque fora/X fecham, foco volta ao botão, escolher página navega e fecha.
- **Rolagem horizontal**: 20 páginas × 6 larguras (360, 390, 768, 1024, 1366, 1920). Sem rolagem da página inteira (o único caso, Etiquetas a 360 px, já existia e foi corrigido). Tabelas extensas continuam com rolagem própria.
- **Capturas antes/depois** (navegador real): `/mnt/project-files/painel-admin-etapa9/`.

Problemas preexistentes registrados (fora do escopo, não alterados):

- Na lista de empréstimos a 1366 px, as colunas "Devolvido em" e "Situação" ficam parcialmente sob a coluna fixa de ações e aparecem com a rolagem da tabela. Agora o atraso também aparece na marca vermelha da linha.
- No celular, títulos longos dos cards da lista de livros são cortados pelos botões de ação.

Limitações: não testado no Windows/XAMPP real nem no Safari; leitores de tela não foram testados com um leitor real (só os atributos ARIA e a ordem de foco).

## 5. Compatibilidade

- Site público: nenhum arquivo alterado; `site-publico-teste.php` passou.
- Migrações: nenhuma. Banco, `.env`, permissões e regras sem mudanças.
- Atualizar no VS Code/XAMPP: `git fetch` e `git checkout claude/project-thread-goek54` (ou mesclar o PR), depois recarregar o painel com Ctrl+F5. Se o menu abrir expandido, é a preferência salva no navegador: clique no botão do topo para recolher (a escolha fica salva).
