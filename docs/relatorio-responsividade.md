# Responsividade e uso no celular — painel administrativo

Adaptação do painel para celular e tablet, sem mudar banco, regras de negócio, permissões ou
rotas. O computador continua com o mesmo layout (menu lateral recolhível, tabelas completas).

## Onde está

| Arquivo | O que faz |
|---|---|
| `admin/assets/css/responsivo.css` (novo) | Folha central, carregada por último em todas as páginas: cabeçalho compacto, barra de atalhos, menu em gaveta, campos de 16 px, alvos de toque, cartões com "Mais detalhes", dashboard, ocorrências e impressão A4. |
| `admin/includes/navegacao-movel.php` (novo) | Barra de atalhos do rodapé (até 900 px): Conferência, Meus lotes, Planilha BDP, Organização e Menu. Segue as permissões do menu. |
| `admin/assets/js/admin.js` | Lupa da pesquisa do topo, menu "Mais ações" (≤ 640 px), botão "Mais detalhes" nos cartões, `body.adm-digitando` (teclado aberto), filtro de listas de usuários. |
| `admin/includes/layout.php`, `header.php`, `sidebar.php` | `viewport-fit=cover`, botão da lupa, grupo "Mais usados" no menu do celular, inclusão da barra de atalhos. |
| `admin/inventario-conferencia.php`, `conferencia.js`, `inventario.css` | Faixa do lote ativo no topo, pesquisa sempre em linha, cartões de resultado, painéis de verificar/registrar em tela cheia (cabeçalho, corpo com rolagem, rodapé fixo, áreas seguras, `dvh`). |
| `admin/lotes.php`, `lote.php`, `includes/acervo-fisico.php`, `form-abrir-lote.php`, `preparo.css` | Lotes em cartões com progresso, etapas do lote (`lote_etapas_html`), capacidade com teclado numérico, filtro de usuários (mais de 8). |
| `admin/planilha-bdp.php` | Cartões compactos no celular; tabela completa no papel. |
| `admin/organizacao-fisica.php`, `organizacao-mapa.php`, `organizacao-mapa.js`, `organizacao.css` | Estantes em 1–2 colunas (consulta de contêiner nos cartões), indicadores recolhíveis, mapa com Lista/Mapa e zoom, rolagem só dentro do mapa. |
| `admin/index.php` | Dashboard: tabelas curtas viram listas e o acesso rápido vira grade 2 × N. |
| demais `.css` | `@media (max-width)` → `@media screen and (max-width)` e `min-width` → `print, (min-width)`, para a impressão nunca pegar o layout de celular. |
| `admin/includes/ajuda/tutoriais/01-primeiros-passos.php`, `18-preparo-exemplares.php` | Novo tutorial "Como usar o painel no celular ou no tablet" e passos atualizados (menu, tabelas, mapa). |

Pontos de quebra usados: 1280, 1100, 900 (gaveta + barra de atalhos), 720 (cartões / uma coluna), 640, 420.

### Mudança visual no computador (intencional)

Os resets `.adm-body button, input { font: inherit }` e `.adm-body select, textarea { font: inherit }`
passaram a usar `:where()`. Antes eles anulavam as classes dos componentes; agora valem as classes:
botões `<button class="adm-button">` ficam com o mesmo peso (600) dos botões feitos com `<a>`, campos de
código (`adm-mono`, `adm-input--code`) usam a fonte monoespaçada prevista e os campos `--sm` ficam com 13 px.
O campo de pesquisa da Conferência fica com 20 px (era 14 px).

## Testes realizados

Ambiente: PHP 8.3 (servidor embutido) + MariaDB, banco com dados fictícios (3 auxiliares, 10 estantes,
inventário em andamento, 2 lotes, 3 ocorrências), Chromium (Playwright).

* **Varredura automática de 53 páginas do painel** em 320×568, 360×800, 390×844, 430×932, 768×1024,
  1024×768, 1366×768, 1920×1080 e em paisagem 568×320 e 844×390: largura da página = largura da tela
  (sem rolagem lateral) em todas, sem erros de JavaScript. Também confere campos com letra menor que 16 px no celular.
* **Site público** (10 páginas) em 320, 360, 390, 768 e 1366: sem rolagem lateral.
* **Capturas conferidas visualmente** (390×844, antes e depois): dashboard, menu, conferência, resultados,
  registrar exemplar, abrir lote, Histórico de Lotes, lote (etapas e carimbagem), Planilha BDP, Organização
  Física, mapa (lista e zoom), ocorrências, acervo, empréstimos, detalhes do exemplar. Conferência nos 8
  tamanhos e em paisagem. Ver `docs/capturas/responsividade/`.
* **Impressão** (PDF A4 gerado pelo Chromium a partir de 390 px e 1366 px): Planilha BDP, lote, relatórios,
  etiquetas de organização e relatório do inventário — sem menu, barras, caixas de seleção nem botões; colunas
  escondidas no cartão voltam no papel.
* **Comparação de fontes** de todos os campos e botões, antes × depois, em 1366 px (resultado na seção acima).
* **24 suítes de teste do projeto** (`admin/tests/*-teste.php`): todas passam.

### Não testado

* Aparelhos reais (iPhone/Safari, Android/Chrome) e teclado virtual de verdade: os testes usaram o Chromium
  emulando toque e tamanhos de tela. Áreas seguras (`env(safe-area-inset-*)`) e `dvh` só se confirmam no aparelho.
* Leitores de tela e Firefox.
* Gráficos do dashboard com muitos dados (o banco de teste tem poucos registros).

## Limitações conhecidas

* No celular o mapa das estantes só mostra (Lista ou Mapa com zoom); para mudar posições, use o computador
  ou os campos numéricos.
* Tabelas muito largas que não viraram cartões (ex.: relatórios detalhados) continuam com rolagem só dentro
  do quadro da tabela.
* Os gráficos seguem a largura do cartão; não foram redesenhados.
