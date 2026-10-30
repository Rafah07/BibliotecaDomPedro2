# Exemplares, localizações, inventário e etiquetas

Este guia explica, em linguagem simples, como usar o acervo físico no painel administrativo.
Tudo depende do banco de dados MySQL configurado (sem banco, as páginas de exemplares mostram só a demonstração antiga).

## Ideia principal: livro × exemplar

- **Livro** (registro bibliográfico): título, autor, editora, ISBN. O ISBN identifica a **edição**, não um volume.
- **Exemplar**: cada volume físico que está na biblioteca. Cada um tem um código próprio, como `BDP-000001`.
- A quantidade das planilhas antigas é **só referência**. Ela não cria exemplares. O sistema mostra três números separados:
  - quantidade na planilha;
  - exemplares físicos cadastrados;
  - exemplares disponíveis para empréstimo.
- Um código BDP nunca se repete e nunca é reaproveitado, mesmo depois de uma baixa.

## Como cadastrar exemplares

1. Abra o livro em **Acervo** e clique em **Cadastrar exemplar**. Outro caminho é **Exemplares → Cadastrar exemplar** e pesquisar o livro.
2. Informe:
   - o estado de conservação;
   - a situação inicial (só "Disponível" aparece para empréstimo);
   - a localização;
   - a quantidade de volumes **que estão com você**;
   - observações, se precisar.
3. Marque a confirmação de que os volumes estão em mãos e clique em **Confirmar cadastro**.
4. O sistema mostra os códigos gerados e o botão **Imprimir etiqueta(s)**.

Também é possível cadastrar durante a conferência do inventário (veja abaixo).

Exemplares não são excluídos. Na página do exemplar existem estas ações, e todas ficam no histórico:

- **Editar** o estado e as observações;
- **Transferir** de localização (só aquele exemplar muda);
- **Mudar situação**: disponível, aguardando liberação, em manutenção, danificado;
- **Registrar extravio**;
- **Dar baixa**.

Exemplar emprestado não pode ser baixado, extraviado nem mudar de situação. Primeiro registre a devolução.

## Como cadastrar localizações

1. Abra **Exemplares → Localizações** e clique em **Nova localização**.
2. Preencha:
   - setor (obrigatório);
   - estante;
   - prateleira;
   - complemento (opcional);
   - um código curto (opcional, ex.: `LIT-E03-P02`).
3. A mesma combinação não pode ser cadastrada duas vezes. Maiúsculas e minúsculas contam como iguais.
4. Localizações não são excluídas: são **desativadas**. Os exemplares que já estão nelas continuam lá.

A aba **Localizações antigas (planilhas)** mostra o texto que veio das planilhas. É só para consulta: esse texto nunca vira localização confirmada sozinho e nunca é alterado.
O sistema não distribui livros por CDD automaticamente. A organização física será definida com a bibliotecária.

## Como iniciar um inventário

1. Abra **Inventário → Novo inventário**.
2. Informe:
   - o nome;
   - a data de início;
   - o responsável;
   - o setor (ou "Acervo inteiro");
   - observações.
3. O inventário fica **em preparação**. Revise os dados e clique em **Iniciar conferência**.
4. Nesse momento a lista de **exemplares previstos** é congelada (todos os exemplares ativos, ou só os do setor escolhido).

Só pode existir um inventário em andamento ou pausado de cada vez. As mudanças de situação possíveis são:

- em preparação → em andamento ou cancelado;
- em andamento → pausado, concluído ou cancelado;
- pausado → em andamento, concluído ou cancelado.

Inventários concluídos ou cancelados ficam bloqueados.

## Como realizar a conferência

1. Na página do inventário, clique em **Conferir exemplares**.
2. Leia o código de barras da etiqueta ou digite o código (ex.: `BDP-000123`, `bdp-123` ou `BDP 123` funcionam).
3. Confira:
   - o estado encontrado;
   - a localização encontrada;
   - a observação (opcional).
4. Clique em **Confirmar conferência**.
5. Se o texto lido não for um código, o sistema pesquisa livros por título, autor ou ISBN. Cada resultado mostra:
   - os exemplares já cadastrados;
   - a quantidade da planilha e a localização antiga (só como referência).
6. Livro em mãos sem código: use **Cadastrar exemplar**, marque a confirmação, e o exemplar novo já fica conferido.

Algumas regras da conferência:

- **Código desconhecido** não cria nada.
- **Leitura repetida**: o sistema avisa, mostra a conferência anterior (data, responsável, local, estado) e não grava de novo. Quem gerencia o inventário pode **corrigir** a conferência, informando o motivo.
- **Localização em conferência** (coluna da direita): escolha a estante que está sendo conferida e ela vem preenchida em cada leitura.
- **Modo rápido**: cada código lido é conferido na hora, com o estado já cadastrado e a localização em conferência.
- Exemplar sem localização: a localização encontrada passa a ser a confirmada.

## Como usar um leitor de código de barras

- Leitores USB comuns funcionam como teclado: "digitam" o código e apertam Enter. Não é preciso instalar nada.
- Deixe o cursor no campo **Leia o código de barras** (ele já abre selecionado). Depois de cada leitura, o campo volta a ficar pronto.
- Atalhos na conferência: **F2** ou **/** voltam ao campo de leitura, e **Esc** limpa o campo.
- O leitor também funciona no campo de pesquisa da lista de **Exemplares**.
- Se o leitor colocar letras trocadas, confira se o teclado do computador está em português (ABNT2) e o leitor no mesmo idioma.

## Como tratar divergências

Na página do inventário, a aba **Pendências** mostra o que precisa de decisão:

- **Encontrado com diferença** (outra localização ou outro estado). As decisões possíveis são:
  - **Atualizar o cadastro com o que foi encontrado**;
  - **Manter o cadastro como está**.
- **Previsto e não localizado**. As decisões possíveis são:
  - **Manter sem alteração (procurar depois)**;
  - **Registrar como extraviado**.
- **Emprestado**: aparece à parte e não é ausência. O inventário nunca encerra empréstimos: a devolução é feita em Empréstimos.

Nenhum exemplar vira extraviado sem uma decisão registrada. O botão **Manter todos os não localizados sem alteração** decide de uma vez, sem marcar extravios.
Quem tem permissão de gerenciar também pode marcar, na própria conferência, "já atualizar o cadastro", e a decisão fica registrada.

## Como concluir um inventário

1. Trate todas as pendências.
2. Clique em **Concluir inventário** e confirme.
3. O sistema grava o resumo final e o resultado por título, e bloqueia o inventário.
4. Em **Relatório**:
   - veja os números (previstos, conferidos, não conferidos, local diferente, estado divergente, cadastrados no inventário, extravios, correções);
   - exporte em **CSV** ou **PDF**.

Os relatórios não têm dados de alunos.

## Como gerar e imprimir etiquetas

1. Escolha os exemplares de uma destas formas:
   - na página do exemplar ou do livro (**Imprimir etiqueta**);
   - logo depois do cadastro;
   - em **Exemplares**, marcando as caixas e clicando **Gerar etiquetas dos selecionados**;
   - em **Exemplares**, com **Etiquetas de todos os filtrados** (até 500 por vez; por exemplo, filtro "Etiqueta ainda não gerada").
2. Em **Etiquetas**:
   - escolha o modelo;
   - informe quantas posições pular na primeira folha (para reaproveitar folha usada);
   - marque, se quiser, o contorno para teste.
3. Confira a **pré-visualização em tamanho real** e clique em **Gerar PDF em tamanho real**.
4. Na impressão, escolha **Tamanho real / 100%** e desligue "Ajustar à página". Faça um teste em papel comum antes de usar a folha de etiquetas.

O que aparece na etiqueta:

- nome da biblioteca;
- código do exemplar;
- código de barras Code 128;
- título curto;
- CDD, **só se aprovada** (nunca a CDD antiga da planilha);
- localização, **só se confirmada**.

Nenhum dado de aluno, senha ou token é impresso. Livros sem exemplar físico não geram etiqueta. Cada exemplar aparece uma vez por geração, e exemplares baixados ficam de fora.

## Como configurar tamanhos e margens das etiquetas

1. Abra **Etiquetas → Modelos e medidas** (perfil administrador ou bibliotecária).
2. Edite um modelo ou crie um novo. Todas as medidas são em milímetros:
   - página;
   - etiqueta;
   - margem superior e esquerda;
   - espaço entre colunas e entre linhas;
   - número de colunas e linhas.
3. Escolha o tipo:
   - **patrimonial**: código e barras em destaque;
   - **lombada**: CDD em destaque, para etiquetas estreitas.
4. Marque o que aparece na etiqueta.
5. O sistema recusa medidas que não cabem na página e avisa se as barras ficarem finas demais para leitura.

Modelos que já vêm cadastrados (confira as medidas na embalagem da sua folha):

- Patrimonial A4 — 3 × 11 (63,5 × 25,4 mm);
- Patrimonial A4 — 2 × 7 (99,1 × 38,1 mm);
- Lombada A4 — 5 × 13 (38,1 × 21,2 mm);
- Individual — 50 × 25 mm (impressora de etiquetas).

O nome impresso vem de `ETIQUETA_BIBLIOTECA` no `.env`. Se ficar vazio, sai "Biblioteca Dom Pedro II".

## Quais funcionalidades dependem da conferência física

Estas ações só fazem sentido com o livro em mãos:

- cadastro de exemplares (gera o código BDP);
- confirmação da localização de cada exemplar;
- impressão e colagem das etiquetas;
- conferência do inventário e decisões sobre ausências;
- empréstimos (só de exemplares cadastrados e disponíveis).

Estas funcionam sem a conferência física:

- cadastro e edição de livros;
- classificação CDD;
- cadastro de localizações e modelos de etiqueta;
- relatórios.

## Permissões

| Perfil | Pode |
| --- | --- |
| Administrador e bibliotecária | Tudo: exemplares, baixas, localizações, inventário (criar, iniciar, decidir, concluir), conferência, etiquetas e modelos. |
| Auxiliar | Cadastrar exemplares, conferir no inventário e imprimir etiquetas. Não cria nem conclui inventário, não decide divergências, não dá baixa e não muda modelos. |
| Consulta | Só visualizar as listas. |

As permissões são conferidas no servidor em cada página e em cada formulário. Os formulários têm proteção CSRF, e as ações ficam registradas em `acoes_administrativas`.

## Banco de dados

A migração `database/migrations/070_exemplares_inventario_etiquetas.sql` é incremental. Ela não apaga nem muda livros, alunos, administradores, classificações, empréstimos ou exemplares existentes, e não cria exemplares.

O que ela acrescenta:

- localizações: complemento, código, descrição e ativação;
- exemplares: a situação "danificado", quem cadastrou, data da etiqueta e dados da baixa;
- a tabela `inventario_itens` (uma linha por exemplar e inventário, sem repetição);
- a tabela `etiqueta_modelos`;
- gatilhos que impedem trocar o código BDP, apagar exemplares e alterar inventários finalizados.

Para aplicar: faça um backup e rode `php database/migrar.php`. As migrações já aplicadas são puladas.

Teste automático (usa um banco temporário com dados fictícios e o apaga no final):

```bash
php admin/tests/inventario-teste.php
```
