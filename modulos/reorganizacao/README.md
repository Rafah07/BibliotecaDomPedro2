# Módulo temporário: Reorganização do Acervo

Módulo **temporário e removível**: a organização por equipes é um apoio ao **Inventário**, que é o
ambiente central. O módulo fica todo nesta pasta (`modulos/reorganizacao/`) mais as migrações
`database/migrations/220_reorganizacao_acervo.sql`, `230_reorganizacao_ajustes.sql` e
`250_reorganizacao_inventario.sql`.
O que ele produz no acervo (obras, exemplares, códigos BDP, carimbagem, localização, CDD, Planilha BDP,
itens do inventário, nome/prazo/instruções do lote) é gravado nas tabelas **permanentes** do sistema e
continua existindo depois que o módulo for removido.

## Um inventário, um sistema de lotes

- **Inventário** → aba **Configurações** → “Ativar organização por equipes”. O período das equipes
  pertence a esse inventário (`reorg_periodos.inventario_id`, migração 250). Um inventário por vez.
  Desligado, o inventário funciona normalmente. Desativar as equipes não conclui o inventário; concluir
  ou cancelar o inventário encerra as equipes dele.
- **Lote** = o lote permanente do inventário (`cadastro_lotes`, com `inventario_id`, `nome`, `prazo`,
  `instrucoes` e `observacoes` desde a migração 240). Formulário **único**: `admin/lote-novo.php` (todos os
  botões “Abrir novo lote” levam a ele). Com as equipes ativas no inventário escolhido, o mesmo formulário
  mostra os participantes do G1, G3 e G4, e o lote ganha a extensão `reorg_lotes` (participantes, etapas,
  pausas, conclusão) na mesma transação — **nunca um segundo lote**.
- **Cadastro do Grupo 2**: obra (nova ou existente) + CDD + exemplares + códigos BDP pelo gerador
  permanente, e os exemplares entram no inventário do lote como “cadastrados na conferência” e
  “encontrados” (`InventarioService::registrarCadastrados`, o mesmo mecanismo da conferência). As linhas
  da planilha daquela obra ficam “Obra localizada”. Exige o inventário em andamento.
- **Indicadores** sem contagem dupla: os números oficiais (previstos nos lotes, cadastrados com código BDP,
  carimbados, organizados) vêm dos lotes permanentes; os temporários (em levantamento, aguardando revisão,
  conferidos pelo G4, pendências, solicitações) aparecem à parte. Registro do G1 não é exemplar.

## Navegação (dentro do Inventário)

Abas do inventário: **Visão geral · Lotes · Equipes e participantes · Conferência · Pendências e
solicitações · Histórico · Configurações**. As abas das equipes só aparecem com as equipes ativas (o
“Histórico das equipes” continua acessível depois). Não há mais item próprio no menu principal.
Cada lote com equipes tem abas internas: Visão geral · G1 · G2 · G3 · G4 · Participantes · Pendências ·
Histórico · Relatório e Planilha BDP (página permanente do lote).

## Como funciona (resumo)

| Quem | Onde | O que faz |
|---|---|---|
| Grupo 2 (administradores) | Painel → **Inventário** (abas do inventário) | Lotes, equipes e participantes, pendências e solicitações, histórico e configurações das equipes. Cada lote tem abas internas (Visão geral, G1 a G4, Participantes, Pendências, Histórico, Relatório e Planilha BDP) |
| Grupo 1 (levantamento) | Botão cinza **Equipe de organização** na tela de login | Um formulário por vez, em sequência (Anterior · Próximo · Confirmar e avançar), com salvamento automático e rascunho local |
| Grupo 3 (carimbação) | idem | Vê a planilha de carimbação do lote e marca cada exemplar carimbado (sem redigitar código) |
| Grupo 4 (conferência/organização) | idem | Digita o código BDP escrito no livro, confere os dados e registra onde o exemplar ficou |

Fluxo de um lote: **levantamento (G1) → revisão e cadastro (G2) → carimbação (G3) → conferência e organização (G4) → conclusão (G2)**.

Participantes entram **só com o nome**, leem as instruções, escolhem a equipe e aguardam o administrador
atribuir um lote ("Aguardando a atribuição de um lote pelo administrador."). Não há conta, senha, link individual, QR code,
leitura de código de barras nem identificador temporário. Os lotes são atribuídos **à mão** pelo administrador.

## Ativar e desativar

- **Equipes no inventário**: o botão "Equipe de organização" só aparece no login enquanto as equipes
  estiverem ativas num inventário (Inventário → Configurações → “Ativar organização por equipes”).
  Desmarcar encerra todas as sessões de participantes (pede confirmação se ainda houver trabalho aberto).
- **Período antigo** (criado antes da migração 250, sem inventário): ao ativar as equipes num inventário,
  o período que já estava ativo e os lotes dele passam a pertencer a esse inventário (nada é recriado).
- **Módulo inteiro**: no `.env`, `REORGANIZACAO_MODULO_ATIVO=false` desliga tudo: o botão some do login,
  as abas das equipes somem do inventário e todas as páginas do módulo respondem 404. Nada é apagado.
- Ajustes opcionais no `.env` (valores padrão entre parênteses):
  `REORGANIZACAO_INTERVALO_SINAL` (15 s), `REORGANIZACAO_TOLERANCIA` (120 s sem sinal = desconectado),
  `REORGANIZACAO_RESERVA_EDICAO` (90 s de reserva de um registro aberto), `REORGANIZACAO_LIMITE_ENTRADAS` (40 entradas/min por IP).
- Os tempos também podem ser ajustados em **Configurações das equipes** (link na configuração do inventário;
  tabela `reorg_configuracoes`, migração 230); o valor salvo lá vale por cima do `.env`.

## Links de coleta da migração 210

A migração 220 grava `COLETA_LINKS_ATIVOS = false` em `configuracoes_sistema`, porque a regra deste módulo
proíbe links de acesso sem login. Os links antigos e tudo o que foi coletado por eles **continuam guardados**;
só deixam de abrir. Para religar: Configurações → "Aceitar os links das equipes" → Sim.

## Exportar o histórico antes de remover

1. Inventário → aba **Histórico** → "Exportar CSV" (histórico imutável de tudo o que aconteceu).
2. No mesmo **Histórico** (ou em Configurações das equipes → Exportar): "Lotes e progresso", "Produção por sessão" e,
   escolhendo um lote no filtro, "Exemplares do lote" (com os códigos BDP), todos em CSV.
3. Faça um backup completo do banco (Configurações → Manutenção → Backup, ou `mysqldump`).

## Arquivos do módulo

- `modulos/reorganizacao/` (tudo): `bootstrap.php`, `config.php`, `src/`, `includes/` (inclusive `ganchos.php`,
  a integração com o inventário), `admin/`, `equipe/`, `assets/`, `tests/`.
- `database/migrations/220_reorganizacao_acervo.sql`, `230_reorganizacao_ajustes.sql` e `250_reorganizacao_inventario.sql`.
- As páginas antigas `admin/index.php`, `lotes.php`, `lote-novo.php`, `periodo.php`, `pendencias.php`, `planilhas.php` e
  `relatorios.php` só redirecionam para as abas do inventário (ficaram para não quebrar favoritos).

## Estruturas permanentes (ficam depois da remoção)

- Migração **240** (`240_lotes_inventario.sql`, permanente): `cadastro_lotes.inventario_id`, `nome`, `prazo`,
  `instrucoes`, `observacoes`. Usada pelo formulário único de lote e pela aba Lotes do inventário.
- `admin/lote-novo.php` (formulário único), `admin/inventario-lotes.php` (aba Lotes),
  `admin/includes/inventario-integracao.php` (abas do inventário, totais dos lotes, pontos de extensão).
- `LoteService::criar/alterarDados/dadosExtras`, `LoteRepository::doInventario/totaisInventario`,
  `InventarioService::registrarCadastrados/motivoSemConferencia`.

## Pontos de integração em arquivos permanentes

Todos toleram a pasta do módulo ausente (verificam `is_file`/`admin_modulo_ativo` antes de incluir) e
nenhum arquivo permanente consulta tabelas `reorg_*`:

| Arquivo | O que foi adicionado |
|---|---|
| `admin/includes/inventario-integracao.php` | pontos de extensão genéricos (`modulo_gancho_registrar`, `modulo_gancho`, `modulo_gancho_html`): carrega `modulos/*/includes/ganchos.php` só de módulos presentes e ligados. Eventos: `inventario.abas`, `inventario.form`, `inventario.salvo`, `inventario.visao`, `inventario.avisos_conclusao`, `inventario.concluido`, `conferencia.painel`, `lote.form`, `lote.criado`, `lote.url`, `lote.painel`, `lote.linha`, `lote.alterado` |
| `admin/inventario-editar.php`, `inventario-detalhes.php`, `inventario-conferencia.php`, `lote-novo.php`, `inventario-lotes.php`, `lote.php` | chamadas a esses eventos (sem o módulo, não mostram nada a mais) |
| `admin/includes/menu.php` | a função genérica `admin_modulo_ativo()` (o item próprio do módulo saiu do menu) |
| `admin/includes/sidebar.php` | condição que esconde itens de módulos inativos ou ausentes (e o grupo que ficar vazio) |
| `admin/login.php` | `include` de `modulos/reorganizacao/includes/login-botao.php` (se existir) |
| `admin/lote.php` | bloqueio da criação de links de coleta quando `COLETA_LINKS_ATIVOS` é falso (regra permanente, pode ficar); com os links desligados, a seção "Equipes por link" vira um aviso curto (os dados antigos continuam consultáveis) |
| `admin/includes/acervo.php` | rótulos das movimentações "carimbo" e "ocorrência" na linha do tempo (correção permanente; não depende do módulo) |
| `admin/tests/coleta-teste.php`, `admin/tests/configuracoes-teste.php` | consideram a configuração gravada pela migração 220 |

## Como remover

1. Exporte o histórico e faça o backup (seção acima).
2. Coloque `REORGANIZACAO_MODULO_ATIVO=false` no `.env` e confira que o sistema segue normal.
3. Apague a pasta `modulos/reorganizacao/`.
4. Opcional: remova o bloco de `include` em `admin/login.php`. Sem a pasta, ele já fica inerte, e os pontos
   de extensão do inventário não carregam nada. **Não** apague a migração 240 nem as páginas permanentes
   (`lote-novo.php`, `inventario-lotes.php`, `includes/inventario-integracao.php`): elas são do sistema.
5. **Opcional e só com backup e autorização**: apague as tabelas do módulo, nesta ordem
   (respeita as chaves estrangeiras):

```sql
DROP TRIGGER IF EXISTS reorg_historico_sem_alteracao;
DROP TRIGGER IF EXISTS reorg_historico_sem_exclusao;
DROP TABLE IF EXISTS reorg_historico;
DROP TABLE IF EXISTS reorg_notificacoes;
DROP TABLE IF EXISTS reorg_solicitacoes;
DROP TABLE IF EXISTS reorg_pendencias;
DROP TABLE IF EXISTS reorg_registro_exemplares;
DROP TABLE IF EXISTS reorg_registros;
DROP TABLE IF EXISTS reorg_atribuicoes;
DROP TABLE IF EXISTS reorg_lote_admins;
DROP TABLE IF EXISTS reorg_lotes;
DROP TABLE IF EXISTS reorg_sessoes;
DROP TABLE IF EXISTS reorg_periodos;
DROP TABLE IF EXISTS reorg_configuracoes;
```

   As colunas `escolhas_obra`, `isbn_situacao` e `cdd_situacao` (migração 230) somem junto com `reorg_registros`,
   e `reorg_periodos.inventario_id` (migração 250) some junto com `reorg_periodos`.
   Não apague os arquivos das migrações 220, 230 e 250 nem as linhas delas em `schema_migracoes`: assim o `migrar.php`
   não tenta recriar as tabelas. (Se preferir apagar o arquivo da migração, a linha em `schema_migracoes` pode ficar.)

## Como conferir a remoção

- A tela de login abre sem o botão "Equipe de organização".
- O inventário mostra só as abas permanentes (Visão geral, Lotes, Conferência, Pendências, Configurações).
- Inventário, Lotes (com nome, prazo e instruções), Planilha BDP e Etiquetas mostram as obras e os exemplares
  criados durante a reorganização; os itens do inventário continuam “encontrados”.
- Os testes permanentes passam: `php admin/tests/lotes-teste.php`, `php admin/tests/preparo-teste.php`, etc.

## Testes

- `php modulos/reorganizacao/tests/reorganizacao-teste.php`: regras T01–T42 (serviços, concorrência, segurança).
  Também roda `reorganizacao-paginas-teste.php`, que testa as páginas por HTTP, inclusive com o módulo desligado.
- `php modulos/reorganizacao/tests/inventario-equipes-teste.php`: o processo do inventário em 17 passos (criar
  inventário, ativar equipes, participantes, um lote só pelo formulário único, registro parcial, encaminhamento,
  revisão e cadastro, códigos BDP, carimbação, conferência do código, organização, indicadores sem contagem dupla,
  conclusão do lote, desativar as equipes, continuar usando o inventário e verificar que nada permanente depende do
  módulo — inclusive com ele desligado).
- `php modulos/reorganizacao/tests/reorganizacao-fluxo-teste.php`: o cenário completo (30 exemplares, 4 grupos, CDD,
  códigos BDP, conclusão, encerramento) e os casos extras. Com Node + Playwright instalados, também abre o navegador
  em 1366×768 e 1280×720, simula perda de conexão e confere a rolagem lateral (`REORG_CAPTURAS=/pasta` guarda as telas).
- Todos criam um banco temporário de desenvolvimento e o apagam no fim (o banco do `.env` não é usado).
  Precisam do MySQL/MariaDB com o usuário do `.env`.

## Limitações conhecidas

- Exemplares organizados sem prateleira cadastrada ficam "Aguardando liberação" para empréstimo até
  receberem localização (aviso aparece na conclusão do lote).
- A atualização das telas é por sinal periódico (15 s), não instantânea.
- O botão do login usa estilo próprio (não depende do CSS do painel).
