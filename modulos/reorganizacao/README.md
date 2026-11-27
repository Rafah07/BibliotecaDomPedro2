# Módulo temporário: Reorganização do Acervo

Módulo **temporário e removível** para a reorganização física do acervo. Ele fica todo nesta pasta
(`modulos/reorganizacao/`) mais as migrações `database/migrations/220_reorganizacao_acervo.sql` e
`230_reorganizacao_ajustes.sql`.
O que ele produz no acervo (obras, exemplares, códigos BDP, carimbagem, localização, CDD, Planilha BDP)
é gravado nas tabelas **permanentes** do sistema e continua existindo depois que o módulo for removido.

## Como funciona (resumo)

| Quem | Onde | O que faz |
|---|---|---|
| Grupo 2 (administradores) | Painel → grupo **Reorganização do acervo** (separado do Inventário) | Central com 6 seções: Visão geral, Lotes, Equipes e participantes, Solicitações e pendências, Histórico, Configurações. Cada lote tem a própria área de trabalho com abas (Visão geral, Grupo 1 a 4, Participantes, Pendências, Histórico) |
| Grupo 1 (levantamento) | Botão cinza **Equipe de organização** na tela de login | Um formulário por vez, em sequência (Anterior · Próximo · Confirmar e avançar), com salvamento automático e rascunho local |
| Grupo 3 (carimbação) | idem | Vê a planilha de carimbação do lote e marca cada exemplar carimbado (sem redigitar código) |
| Grupo 4 (conferência/organização) | idem | Digita o código BDP escrito no livro, confere os dados e registra onde o exemplar ficou |

Fluxo de um lote: **levantamento (G1) → revisão e cadastro (G2) → carimbação (G3) → conferência e organização (G4) → conclusão (G2)**.

Participantes entram **só com o nome**, leem as instruções, escolhem a equipe e aguardam o administrador
atribuir um lote ("Aguardando a atribuição de um lote pelo administrador."). Não há conta, senha, link individual, QR code,
leitura de código de barras nem identificador temporário. Os lotes são atribuídos **à mão** pelo administrador.

## Ativar e desativar

- **Período**: o botão "Equipe de organização" só aparece no login enquanto houver um período ativo
  (Reorganização do Acervo → Configurações → Período). Encerrar o período encerra todas as sessões de participantes.
- **Módulo inteiro**: no `.env`, `REORGANIZACAO_MODULO_ATIVO=false` desliga tudo: o botão some do login,
  o item some do menu e todas as páginas do módulo respondem 404. Nada é apagado.
- Ajustes opcionais no `.env` (valores padrão entre parênteses):
  `REORGANIZACAO_INTERVALO_SINAL` (15 s), `REORGANIZACAO_TOLERANCIA` (120 s sem sinal = desconectado),
  `REORGANIZACAO_RESERVA_EDICAO` (90 s de reserva de um registro aberto), `REORGANIZACAO_LIMITE_ENTRADAS` (40 entradas/min por IP).
- Os tempos também podem ser ajustados pela página **Configurações** do módulo (tabela `reorg_configuracoes`,
  migração 230); o valor salvo lá vale por cima do `.env`.

## Links de coleta da migração 210

A migração 220 grava `COLETA_LINKS_ATIVOS = false` em `configuracoes_sistema`, porque a regra deste módulo
proíbe links de acesso sem login. Os links antigos e tudo o que foi coletado por eles **continuam guardados**;
só deixam de abrir. Para religar: Configurações → "Aceitar os links das equipes" → Sim.

## Exportar o histórico antes de remover

1. Reorganização do Acervo → **Histórico** → "Exportar CSV" (histórico imutável de tudo o que aconteceu).
2. No mesmo **Histórico** (ou em Configurações → Exportações): "Lotes e progresso", "Produção por sessão" e,
   escolhendo um lote no filtro, "Exemplares do lote" (com os códigos BDP), todos em CSV.
3. Faça um backup completo do banco (Configurações → Manutenção → Backup, ou `mysqldump`).

## Arquivos do módulo

- `modulos/reorganizacao/` (tudo): `bootstrap.php`, `config.php`, `src/`, `includes/`, `admin/`, `equipe/`, `assets/`, `tests/`.
- `database/migrations/220_reorganizacao_acervo.sql` e `database/migrations/230_reorganizacao_ajustes.sql`.
- As páginas antigas `admin/periodo.php`, `pendencias.php`, `planilhas.php` e `relatorios.php` só redirecionam
  para as novas seções (ficaram para não quebrar favoritos).

## Pontos de integração em arquivos permanentes

Todos toleram a pasta do módulo ausente (verificam `is_file` antes de incluir):

| Arquivo | O que foi adicionado |
|---|---|
| `admin/includes/menu.php` | grupo próprio "Reorganização do acervo" com o item `'reorganizacao'` (`'modulo' => 'reorganizacao'`) e a função genérica `admin_modulo_ativo()` |
| `admin/includes/sidebar.php` | condição que esconde itens de módulos inativos ou ausentes (e o grupo que ficar vazio) |
| `admin/login.php` | `include` de `modulos/reorganizacao/includes/login-botao.php` (se existir) |
| `admin/lote.php` | bloqueio da criação de links de coleta quando `COLETA_LINKS_ATIVOS` é falso (regra permanente, pode ficar); com os links desligados, a seção "Equipes por link" vira um aviso curto (os dados antigos continuam consultáveis); aviso com link quando o lote pertence à reorganização (só com o módulo presente) |
| `admin/includes/acervo.php` | rótulos das movimentações "carimbo" e "ocorrência" na linha do tempo (correção permanente; não depende do módulo) |
| `admin/tests/coleta-teste.php`, `admin/tests/configuracoes-teste.php` | consideram a configuração gravada pela migração 220 |

## Como remover

1. Exporte o histórico e faça o backup (seção acima).
2. Coloque `REORGANIZACAO_MODULO_ATIVO=false` no `.env` e confira que o sistema segue normal.
3. Apague a pasta `modulos/reorganizacao/`.
4. Opcional: remova o item `'reorganizacao'` de `admin/includes/menu.php` e o bloco de `include` em
   `admin/login.php`. Sem a pasta, os dois já ficam inertes.
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

   As colunas `escolhas_obra`, `isbn_situacao` e `cdd_situacao` (migração 230) somem junto com `reorg_registros`.
   Não apague os arquivos das migrações 220 e 230 nem as linhas delas em `schema_migracoes`: assim o `migrar.php`
   não tenta recriar as tabelas. (Se preferir apagar o arquivo da migração, a linha em `schema_migracoes` pode ficar.)

## Como conferir a remoção

- A tela de login abre sem o botão "Equipe de organização".
- O menu do painel não mostra "Reorganização do Acervo".
- Inventário, Lotes, Planilha BDP e Etiquetas mostram as obras e os exemplares criados durante a reorganização.
- Os testes permanentes passam: `php admin/tests/lotes-teste.php`, `php admin/tests/preparo-teste.php`, etc.

## Testes

- `php modulos/reorganizacao/tests/reorganizacao-teste.php`: regras T01–T42 (serviços, concorrência, segurança).
  Também roda `reorganizacao-paginas-teste.php`, que testa as páginas por HTTP, inclusive com o módulo desligado.
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
