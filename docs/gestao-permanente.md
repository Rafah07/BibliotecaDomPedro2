# Gestão permanente do acervo (migração 190)

Esta etapa prepara o sistema para continuar sendo a ferramenta da biblioteca **depois** da
reorganização inicial: correções bibliográficas com vários colaboradores, registro de várias
cópias de uma vez, qualidade do catálogo, versões da Planilha BDP, confiabilidade (migrações,
backup, falhas de conexão) e o ciclo de vida completo dos exemplares (aquisição, ficha, baixa,
inventário periódico, circulação, devolução e ocupação das prateleiras).

Aplicar no XAMPP (faz a cópia de segurança antes, sozinho):

```
C:\xampp\php\php.exe database/migrar.php --verificar
C:\xampp\php\php.exe database/migrar.php
```

A migração `190_gestao_permanente_acervo.sql` só **acrescenta** tabelas, colunas e índices:
não apaga dados, não muda códigos BDP, lotes, exemplares, empréstimos nem dados bibliográficos.

---

## 1. Obras × exemplares (dados compartilhados)

- O modelo já era normalizado: `livros` guarda a obra/edição (título, autores em `livro_autores`,
  ISBN, editora, ano, edição, categoria em `livro_categorias`, CDD em `classificacoes_cdd`, sinopse,
  capa) e `exemplares` guarda a cópia física (código BDP, lote, localização, conservação, carimbo,
  situação, ocorrências, empréstimos, histórico) com `exemplares.livro_id`.
- Nenhuma tabela de exemplares guarda cópia de título/autor: toda tela (ficha, acervo, conferência,
  pesquisa, catálogo público, Planilha BDP atual) lê a obra pelo vínculo. Corrigir a obra corrige
  todas as cópias, sem atualizar exemplar por exemplar.
- Cópias **históricas** (de propósito): `registros_legados` (planilha original, imutável),
  `planilha_bdp_versoes` (o que foi impresso), `comparacoes` (resultados salvos) e
  `livro_verificacoes` (antes/depois de cada alteração).
- Corrigir só uma cópia de outra edição: ficha do exemplar → **Corrigir a obra deste exemplar**
  (reaproveita `ComparacaoPlanilhaService::corrigirObraExemplar`: mesmo id e código BDP,
  histórico em `exemplar_obra_correcoes` e `movimentacoes`).

## 2. Edição da obra, verificação e histórico

- `admin/editar-livro.php` (botão **Editar dados da obra**) nunca bloqueia por a obra estar
  verificada, ter exemplares carimbados, lotes encerrados, empréstimos ou linhas da planilha.
- Aviso: “Esta obra possui N exemplares. As informações bibliográficas atualizadas serão exibidas
  automaticamente em todos eles.”
- **Salvar e marcar dados como verificados** (`LivroRepository::atualizar(..., $marcarVerificado)`).
  Alteração relevante sem marcar devolve a obra para “Dados não verificados”
  (`ObraVerificacaoService`), sem exigir nova conferência física dos exemplares.
- Histórico: `livro_verificacoes` (campo, antes, depois, administrador, data, origem). Exibido na
  ficha da obra em **Histórico dos dados bibliográficos**; a comparação com a planilha original
  continua em “Dados da importação”.

## 3. Edição simultânea e controle de concorrência

- **Versão do registro**: `livros.versao` (soma 1 a cada gravação, em
  `ObraVerificacaoService::novaVersao`, chamada por todo fluxo que altera a obra). O formulário
  envia a versão aberta; `LivroRepository::atualizar` compara com a linha bloqueada
  (`SELECT ... FOR UPDATE`) e, se mudou, lança `ConflitoEdicaoException` com os dados atuais —
  nada é gravado. (Antes da 190, a data `atualizado_em` continua sendo aceita.)
- **Comparação** (`admin/includes/conflito-edicao.php`): para cada campo, “Quando você abriu”
  (hidden `base`), “Salvo agora” e “Sua alteração”; estados *Só você alterou*, *Alterado pelo outro
  colaborador*, *Os dois alteraram*. O formulário volta mesclado (o que só um alterou fica; nos
  conflitos, o digitado), com escolha **manter o meu / usar o salvo**. Nada digitado é descartado.
- **Aviso de presença** (`obra_edicoes`, `ObraEdicaoService`, `admin/api/acervo/edicao.php`,
  `assets/js/gestao.js`): o navegador confirma a cada 30 s; a marca expira em 90 s sem
  confirmação (navegador fechado, internet caiu) e é retirada ao salvar/sair (`sendBeacon`).
  Só aviso: nunca bloqueia consulta, gravação ou registro de exemplares. O nome aparece para quem
  tem `livros.editar`; os demais veem “outro colaborador”.

## 4. Registro de vários exemplares

- `ExemplarService::cadastrarEmQuantidade()` — usado por `admin/cadastrar-exemplar.php`
  (**Registrar exemplar** / **Registrar vários exemplares**) e pelas Aquisições.
- Uma única transação: chave da operação → livro (`LOCK IN SHARE MODE`) → sequência BDP
  (`FOR UPDATE`) → lote (`FOR UPDATE`, vagas) → N inserções. Falha em qualquer cópia desfaz tudo
  (inclusive a sequência e o contador do lote).
- Cada cópia é um exemplar individual com código `BDP-000000` gerado pelo servidor (mesma sequência
  global, nunca reaproveitada), ordem própria no lote e entrada na Planilha BDP.
- Dados comuns (situação, localização sugerida, observações) + diferenças por cópia
  (conservação, observação/ocorrência, localização). Conservação comum **só** com a marcação
  “Conferi todas as cópias…”; sem ela, cada cópia fica com a própria avaliação ou “Não avaliado”.
- Lote: `LoteCapacidadeException` (vagas restantes) quando não cabem todas — a página oferece
  “Registrar só N neste lote”, “Selecionar outro lote aberto” ou cancelar; nunca divide entre
  lotes. Ao encher, o lote fecha sozinho (fluxo da migração 160 preservado). Disputa pelas últimas
  vagas: a linha do lote é bloqueada, então a capacidade nunca é ultrapassada.

## 5. Idempotência e perda de conexão

- `requisicoes_idempotentes`: a página gera uma chave (32 hex) ao abrir o formulário; ela é
  gravada **na mesma transação** dos exemplares, com o resultado (ids e códigos).
  - Reenvio após sucesso (clique duplo, recarregar, resposta perdida): `OperacaoJaConcluidaException`
    devolve os códigos já atribuídos; nada é criado.
  - Dois envios simultâneos com a mesma chave: o segundo espera no `INSERT` da chave e recebe 1062
    quando o primeiro termina → mesmo resultado.
  - Falha antes do commit: a chave some com o rollback; reenviar grava uma vez.
- Conexão perdida (2006/2013): `Migracoes::conexaoPerdida()`; a página consulta
  `resultadoAnterior($chave)` antes de responder e mostra “Não foi possível confirmar se o cadastro
  foi concluído…” quando o resultado é incerto.
- Formulário protegido no navegador (`data-protegido`, `gestao.js`): guarda o preenchimento
  (inclusive a chave) até o servidor confirmar; ao reabrir, aparece **Dados recuperados.**
  Complementa os rascunhos no servidor (`formulario_rascunhos`, migração 160).

## 6. Mensagens de erro e registro técnico

- `Biblioteca\Support\RegistroErros`: cada falha recebe um código `ERR-AAAAMMDD-xxxxxx` mostrado ao
  funcionário e gravado em `storage/logs/erros-AAAA-MM.log` (JSON por linha: data, operação,
  arquivo:linha, código técnico, chave da operação, usuário, página). Sem senhas, sem SQL
  (“near …” é cortado), sem caminhos completos. Pasta bloqueada (`storage/.htaccess`) e fora do Git.
- `fisico_erro_tecnico()` e `config_erro_tecnico()` passam a usar esse registro.
- Mensagens: “Não foi possível concluir o cadastro. Suas informações foram preservadas.”,
  “A conexão com o banco foi interrompida…”, banco desatualizado (1054/1146) e falta de permissão
  (1142/1143/1044/1045) com orientação ao responsável.

## 7. Planilha BDP — versões

- `planilha_bdp_versoes` (imutável) + `PlanilhaBdpVersaoService` + `admin/planilha-bdp-versoes.php`
  (link **Planilha BDP (versões)** no relatório do lote).
- Emitir: o servidor numera (Versão 01, 02…), com o lote bloqueado, e guarda a cópia das linhas
  (ordem, código, obra, título, autor, edição), responsável, data e motivo (sem motivo, as
  diferenças são registradas).
- Assinatura SHA-256 só dos campos relevantes para a carimbagem (ordem, código, exemplar, obra,
  título, autor): confirmar carimbo, mudar localização ou reimprimir **não** criam versão. Emitir
  sem mudança devolve a versão atual.
- Desatualizada: aviso no lote e na página das versões, lista do que mudou e destaque quando muda
  a identificação física (ordem, código ou obra). A reimpressão de versão antiga traz a faixa
  “ATENÇÃO: esta versão está desatualizada”. Folha A4 com lote, versão, data, quantidade e
  “Página X de Y” (`@page`).

## 8. Central de Qualidade do Acervo

- `admin/qualidade.php` (Acervo → Qualidade do acervo; atalhos no Inventário e no dashboard),
  `QualidadeAcervoService`, tabelas `qualidade_analises`, `qualidade_pendencias`, `qualidade_decisoes`.
- A análise roda sob demanda (**Atualizar análise**) ou pela rotina
  `C:\xampp\php\php.exe database/analisar-qualidade.php` (Agendador de Tarefas). Abrir a página só
  lê a tabela, paginada e filtrável (situação, gravidade, área, tipo, origem, responsável).
- Verificações: obra sem autor/categoria/CDD (informativo), obra com exemplares não verificada
  (informativo), ISBN inválido/incompleto e ano impossível (atenção), registro importado ligado a
  obra arquivada, possíveis duplicidades (mesmo título+autor ou mesmo ISBN; edições diferentes
  rebaixadas a informativo; aviso “obra manual pode corresponder a obra importada”), grafias de
  autores/editoras/categorias, exemplar em obra arquivada, código BDP fora do padrão, sequência
  atrás do maior código, situação × empréstimos contraditória, disponível com bloqueio,
  localização desativada, lote inconsistente, divergência de inventário concluído sem decisão,
  ocorrência aberta > 30 dias; trabalho normal (aguardando carimbo/organização) agregado por lote.
- Decisões com justificativa (“informação indisponível”, “não é duplicidade”…) guardam a
  assinatura dos dados: se o registro mudar, a pendência volta a ser avaliada.
- Padronização: só grafias com a mesma chave (sem acentos/maiúsculas/pontuação, “SOBRENOME, Nome”,
  “Editora/Ltda”, abreviações como “Lit.”). Aplicação só após comparar as obras afetadas;
  histórico por obra (origem `padronizacao`) sem retirar a verificação.
- Duplicidades: comparação lado a lado; “São obras diferentes”, corrigir dados, **Relacionar
  registros importados** (`associarRegistro`) e **Unificar cadastros** (`obra_unificacoes`,
  `livros.unificado_em_id`): exemplares passam com os mesmos códigos, linhas da planilha guardam a
  obra original, a obra unificada é arquivada; edições diferentes exigem confirmação explícita;
  exemplar emprestado impede. Opção de cópia de segurança antes.

## 9. Migrações e diagnóstico

- `database/migrar.php`:
  - confere a conexão (`SELECT 1`) e as permissões (`SHOW GRANTS`, `Migracoes::privilegios`) antes;
  - `--verificar` (só leitura) mostra versão, permissões e a situação real de cada pendente
    (não iniciada / **parcialmente aplicada** / estrutura completa sem registro);
  - registra cada tentativa e cada comando em `schema_migracoes_execucoes` / `schema_migracoes_passos`
    e em `storage/logs/migracoes.log`;
  - na retomada, confere a estrutura real: o que já existe por inteiro é pulado; comandos de dados
    já concluídos na tentativa interrompida não são repetidos;
  - “server has gone away”: para, não marca como concluída, registra “interrompida” e explica
    como retomar; aumenta os tempos de espera da sessão em operações longas;
  - nunca repara, apaga ou desliga a cópia de segurança para conseguir aplicar.
- `admin/diagnostico.php` (Configurações → Diagnóstico do sistema, `DiagnosticoCompleto`): PHP,
  extensões, conexão, versão MySQL/MariaDB, limites (max_allowed_packet), permissões, migrações
  pendentes/parciais/interrompidas, tabelas e colunas necessárias, gatilhos de proteção, sequência
  BDP, integridade exemplar × empréstimo, pastas, proteção pública, espaço em disco, último backup,
  teste de recuperação e erros recentes. “Não verificado” nunca aparece como normal; com o banco
  fora do ar, as verificações independentes continuam.

## 10. Backups

- `BackupService::testarRestauracao()` (botão **Testar recuperação** em Manutenção e backups):
  confere o SHA-256, cria um banco separado e temporário `<banco>_verifica_xxxxxx`, importa a cópia,
  compara tabelas e linhas com o registro da cópia e apaga o temporário. O banco da biblioteca
  nunca é destino. Sem permissão para criar bancos (hospedagem compartilhada), informa
  “Não realizado” e remete ao teste manual (docs/backup-recuperacao.md, seção 7).
- A lista mostra data, tipo, responsável, tamanho, conferência, arquivo ausente e o resultado do
  teste. As cópias não contêm o `.env`; a pasta continua bloqueada.

## 11. Ciclo de vida do exemplar

- **Ficha** (`detalhes-exemplar.php`): busca por código BDP no topo, entrada no acervo e
  aquisição, lote, quem cadastrou, carimbagem, localização e **Histórico de localizações**,
  conservação, ocorrências, resumo de empréstimos, inventários, correções de obra e baixa.
- **Aquisições** (`aquisicoes.php`, `aquisicoes`, `exemplares.aquisicao_id/data_entrada`):
  origem, data, quantidade, documento, observações; registro dos exemplares (obra existente ou nova)
  sem inventário; acompanhamento do preparo.
- **Baixas** (`exemplares.baixa_tipo/baixado_por/baixa_observacao`, `baixas.php`): motivos
  padronizados, justificativa, responsável; recusa com empréstimo ativo; cadastro, código e
  histórico preservados; consulta e exportação.
- **Inventário periódico** (`inventarios.tipo`): só exemplares ativos, localização atual como
  referência, sem planilha original, sem códigos novos, sem lotes; históricos preservados.
- **Circulação** (`relatorio-circulacao.php`): empréstimos/devoluções no período, ativos, atrasados,
  mais emprestadas, procura por cópia, categorias, pouca circulação, série mensal. Só contagens.
- **Devolução com conservação** (`emprestimos.conservacao_anterior/devolucao`): estado agora, dano
  (ocorrência) e decisão de bloquear; a liberação respeita outras ocorrências abertas.
- **Capacidade das prateleiras** (`localizacoes.capacidade_estimada`): opcional; ocupação estimada
  (com espaço, próxima ≥ 85%, possivelmente lotada ≥ 100%, vazia) na Organização Física e no mapa;
  aviso com confirmação na movimentação em lote e aviso na transferência individual. Nunca bloqueia.

## 12. Permissões (verificadas no servidor)

| Permissão | Administrador | Bibliotecária | Auxiliar | Consulta |
|---|:-:|:-:|:-:|:-:|
| `qualidade.ver` (Central de Qualidade) | ✔ | ✔ | ✔ | |
| `qualidade.resolver` (analisar, justificar, padronizar) | ✔ | ✔ | | |
| `obras.unificar` | ✔ | ✔ | | |
| `aquisicoes.registrar` | ✔ | ✔ | ✔ | |
| `exemplares.baixar` (baixa) | ✔ | ✔ | | |
| `sistema.manutencao` (diagnóstico, backups, teste de recuperação) | ✔ | | | |
| `relatorios.ver` (circulação) | ✔ | ✔ | ✔ | ✔ |

## 13. Desempenho

- Ficha da obra (`detalhes-livro.php`) passou a ler só a obra consultada (`acervo_escopo_livro()`),
  em vez de carregar todo o acervo e todo o histórico de movimentações a cada abertura.
- Central de Qualidade: análise sob demanda; leitura paginada com índices
  (`qualidade_pendencias_filtro_idx`, `_tipo_idx`, `_alvo_idx`). Dashboard faz uma única contagem.
- Novos índices: `livros.editora`, `emprestimos (exemplar_id, data_emprestimo)`,
  `exemplares (situacao, baixado_em)`, `exemplares.aquisicao_id`.
- Versões da planilha: a situação lê só os exemplares do lote (limitado pela capacidade).
