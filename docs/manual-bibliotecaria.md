# Manual da bibliotecária — Biblioteca Dom Pedro II

Guia rápido do painel administrativo. Todos os exemplos usam **dados fictícios** (livros, alunos e códigos inventados).

> No computador da biblioteca, o painel abre em `http://localhost/BibliotecaDomPedro2/admin/` (ou no endereço que a escola definir quando o sistema for publicado).

**Lembrete importante:** a biblioteca identifica cada livro físico **somente pelo código escrito na etiqueta**, como `BDP-000123`. Não há código de barras nem QR Code. Você lê o código e digita.

---

## 1. Como entrar no sistema

1. Abra o endereço do painel no navegador.
2. Digite o seu **e-mail** e a sua **senha** e clique em **Entrar**.
3. Cada pessoa tem a sua conta. Não use a conta de outra pessoa nem compartilhe a sua.
4. Errou a senha várias vezes? O acesso fica bloqueado por 15 minutos. Espere e tente de novo, ou peça ao administrador para redefinir a senha.
5. Para trocar a sua senha: menu do seu nome (canto superior) → **Meu perfil**. Use pelo menos 10 caracteres, com letras e números.

Se você ficar muito tempo sem usar o painel (padrão: 2 horas), ele pede o login de novo.

## 2. Como pesquisar livros

1. Clique em **Acervo** no menu.
2. No campo de pesquisa, digite parte do título, autor, editora ou ISBN. Acentos não importam: `coracao` encontra "Coração".
   - Exemplo: digite `pequeno` para encontrar *O Pequeno Explorador* (livro fictício).
3. Use os filtros (categoria, situação da CDD, etc.) e a ordenação para refinar.
4. Clique no título para abrir os **detalhes do livro**: dados, exemplares físicos, CDD e histórico.

Para encontrar um exemplar pelo código, use **Exemplares** e digite o código (ex.: `BDP-000045`).

## 3. Como cadastrar um livro

1. **Acervo → Cadastrar livro.**
2. Preencha o **Título do livro** (obrigatório) e o que souber: autor, subtítulo, ISBN, editora, ano, edição, idioma, categoria, assunto, palavras-chave e sinopse.
   - Exemplo: Título *Histórias do Rio Azul*, autora *Marina Fictícia*, editora *Editora Exemplo*, ano 2020.
3. O campo **CDD** só é preenchido se você já tiver a classificação conferida. Uma CDD antiga das planilhas **nunca** é aprovada sozinha: ela passa pela **Classificação CDD**.
4. Clique em **Salvar livro** (ou **Salvar e adicionar exemplares**, para já cadastrar os volumes físicos).

Livro errado ou duplicado não é apagado: use **Arquivar** na página do livro (ele sai do site público, mas o histórico fica).

## 4. Como cadastrar um exemplar físico

O **livro** é a obra (título, autor). O **exemplar** é cada volume físico que está na prateleira.

1. Abra o livro em **Acervo** e clique em **Cadastrar exemplar** (ou **Exemplares → Cadastrar exemplar** e pesquise o livro).
2. Informe o estado de conservação, a situação inicial (**Disponível** para poder emprestar), a localização e a **quantidade de volumes que estão com você**.
3. Marque a confirmação de que os volumes estão em mãos e clique em **Confirmar cadastro**.

Só cadastre exemplares que você está vendo: a quantidade das planilhas antigas não vira exemplar automaticamente.

## 5. Como gerar o código BDP

Você **não** escolhe nem digita o código novo: o sistema gera sozinho, na ordem, no momento do cadastro do exemplar (passo 4).

- Exemplo: se o último exemplar cadastrado foi `BDP-000120`, os dois próximos recebem `BDP-000121` e `BDP-000122`.
- A tela mostra os códigos gerados logo após o cadastro.
- Um código **nunca se repete** e **nunca é reaproveitado**, nem depois de uma baixa ou extravio.
- Escreva ou cole a etiqueta no livro antes de guardá-lo.

Ao digitar um código em qualquer tela: maiúsculas ou minúsculas tanto faz e o hífen pode faltar (`bdp000121` funciona), mas os **6 números são obrigatórios**. `BDP-121` mostra "Código incompleto", para evitar escolher o livro errado.

## 6. Como imprimir etiquetas escritas

As etiquetas têm só texto, por exemplo:

```
BIBLIOTECA DOM PEDRO II
      BDP-000121
```

1. Escolha os exemplares: logo depois do cadastro (**Imprimir etiqueta(s)**), na página do exemplar ou do livro, ou em **Exemplares** marcando as caixas e clicando em **Gerar etiquetas dos selecionados**.
2. Em **Etiquetas**, escolha o modelo:
   - "Patrimonial individual — 50 × 20 mm" para impressora de etiquetas;
   - um modelo A4 (várias por folha) para impressora comum. Se a folha já foi usada, informe quantas posições pular.
3. Clique em **Atualizar pré-visualização**, confira e clique em **Gerar PDF em tamanho real**.
4. Na janela de impressão, escolha **Tamanho real / 100%** e desligue "Ajustar à página". Teste antes em papel comum.

Etiqueta de **lombada** só sai definitiva com a CDD aprovada. Sem CDD aprovada sai a etiqueta provisória (só o código BDP).

## 7. Como cadastrar uma localização

1. **Exemplares → Localizações → Nova localização.**
2. Preencha o setor (obrigatório), a estante, a prateleira e, se quiser, um complemento e um código curto.
   - Exemplo: setor *Literatura infantil*, estante *E03*, prateleira *P02*, código `LIT-E03-P02`.
3. Salve. A mesma combinação não pode ser cadastrada duas vezes.
4. Para mudar um exemplar de lugar: abra o exemplar → **Transferir**.

Localizações não são apagadas, só **desativadas**.

## 8. Como iniciar um inventário

1. **Inventário → Novo inventário.**
2. Informe o nome (ex.: *Inventário 1º semestre — Literatura infantil*), a data de início, o responsável e o setor (ou "Acervo inteiro").
3. Salve. O inventário fica **em preparação**.
4. Confira os dados e clique em **Iniciar conferência**. A lista de exemplares previstos é fixada nesse momento.

## 9. Como conferir livros

1. Na página do inventário, clique em **Conferir exemplares**.
2. Pegue o livro na prateleira, leia o código da etiqueta e digite no campo **Digite o código do exemplar** (ex.: `BDP-000121`). Pressione **Enter**.
3. Confira se o título mostrado é o do livro em mãos, ajuste o estado ou a localização se precisar e clique em **Confirmar conferência**.
4. Livro **sem etiqueta**? Digite o título para pesquisar. Se ele ainda não tem exemplar, use **Cadastrar exemplar** ali mesmo: ele já fica conferido e ganha o próximo código.
5. Quando terminar, trate as pendências (não encontrados, divergências) e clique em **Concluir inventário**. O relatório fica em **Relatório do inventário**.

Conferir o mesmo exemplar duas vezes não duplica nada: o sistema avisa que ele já foi conferido.

## 10. Como registrar empréstimos

1. **Empréstimos → Novo empréstimo.**
2. **Aluno:** digite a matrícula (mais seguro) ou o nome e clique em **Pesquisar aluno**. Escolha o aluno certo; em caso de nomes iguais, confira a matrícula e a turma.
   - Exemplo: matrícula `2026000` — *Aluno Exemplo da Silva*, 6º A (fictício).
3. **Livro e exemplar:** digite o código BDP da etiqueta (ex.: `BDP-000121`) e pressione **Enter**.
4. **Resumo e confirmação:** confira o título, o código e a data de devolução, marque a confirmação e clique em **Confirmar empréstimo**.

O sistema não deixa emprestar um exemplar que já está emprestado, em manutenção, extraviado ou baixado, nem emprestar para aluno inativo.

## 11. Como registrar devoluções

1. **Empréstimos → Devoluções.**
2. Digite o código BDP do livro devolvido (ex.: `BDP-000121`) e pressione **Enter**.
3. O sistema encontra o empréstimo em aberto. Confira o aluno e o livro.
4. Se o livro voltou danificado, marque a opção de enviar para manutenção.
5. Clique em **Registrar devolução**. O exemplar volta a ficar disponível.

A devolução nunca apaga o empréstimo: ele vai para o histórico.

## 12. Como renovar empréstimos

1. Em **Empréstimos**, abra o empréstimo (ou procure pelo aluno ou pelo código BDP).
2. Clique em **Renovar**.
3. Confira a **nova data de devolução** e clique em **Renovar empréstimo**.

Os dias da renovação e o limite de renovações seguem as regras definidas em **Sistema → Configurações**.

## 13. Como consultar atrasos

1. **Empréstimos → Atrasos.**
2. A lista mostra os empréstimos vencidos, do mais antigo para o mais recente, com os dias de atraso, o aluno, a turma e o livro.
3. Clique no empréstimo para ver os detalhes, renovar ou registrar a devolução.

O sistema não cobra multa e não envia mensagens: o contato com o aluno é feito pela escola.

## 14. Como utilizar relatórios

1. Clique em **Relatórios**.
2. Escolha a seção: Acervo, Exemplares físicos, Empréstimos e devoluções, Atrasos, Alunos, Inventários, Classificação CDD ou Atividades.
3. Escolha o período (ex.: *01/03/2026 a 30/06/2026*) e os filtros.
4. Para baixar, use **CSV**, **Excel (XLSX)** ou **PDF** em cada tabela.

Relatórios com nomes de alunos são **documentos internos**: não publique, não envie por WhatsApp ou e-mail pessoal e apague o arquivo depois de usar. Cada exportação fica registrada.

## 15. Como importar alunos localmente

Faça isto **somente** no computador autorizado da biblioteca, com a planilha oficial da secretaria.

1. **Importar Alunos → Nova importação de alunos.**
2. Escolha o arquivo `.xlsx` ou `.csv`. Para testar o formato, use **Baixar modelo** (tem dois alunos fictícios).
3. **Mapear colunas:** indique qual coluna é Nome, Matrícula, Turma, Turno e E-mail. Deixe as outras como **Ignorar coluna** (CPF, endereço, telefone, responsáveis não são importados).
4. **Prévia:** confira quantos serão incluídos, atualizados e os erros. Nada é gravado ainda.
5. Escolha o que fazer com alunos já cadastrados (o recomendado é deixar como estão) e clique em **Confirmar importação**.
6. A planilha **não fica guardada** no sistema. Depois de importar, apague a planilha do computador (inclusive da pasta Downloads e da lixeira).

## 16. Como realizar backup

Somente o **Administrador** faz a cópia de segurança.

1. **Sistema → Manutenção → Cópia de segurança.**
2. Marque a confirmação e clique em **Baixar cópia de segurança**.
3. Guarde o arquivo em local protegido (pendrive ou HD da escola guardado em local trancado). **Nunca** na pasta do site, no GitHub, em e-mail pessoal ou nuvem particular.
4. Copie também a pasta `uploads/capas/` (as capas não vão no arquivo).

Restaurar uma cópia é tarefa do responsável técnico, sempre num banco novo. Veja [`backup-recuperacao.md`](backup-recuperacao.md).

## 17. Como sair do sistema com segurança

1. Clique no seu nome (canto superior) e em **Sair**, ou use **Sair** no final do menu.
2. Feche o navegador se o computador for compartilhado.
3. Ao se afastar do computador por pouco tempo, bloqueie o Windows (tecla **Windows + L**).

---

### Dúvidas comuns

- **"Código incompleto"**: faltam números. Digite os 6 números depois de `BDP` (ex.: `BDP-000045`).
- **"Código não encontrado"**: confira a etiqueta. Se o livro não tem exemplar cadastrado, cadastre (passo 4).
- **"Exemplar já emprestado"**: registre a devolução anterior antes de emprestar de novo.
- **Voltei para a tela de login sozinha**: a sessão terminou por inatividade. Entre de novo; o que não foi salvo precisa ser refeito.
- **Não aparece um menu**: o seu perfil não tem essa permissão. Fale com o administrador.
