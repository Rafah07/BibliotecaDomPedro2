# Configurações gerais, perfis e manutenção (Etapa 6)

Menu do painel: **Sistema → Configurações**, com as subpáginas **Perfis e permissões** e **Manutenção**.

## De onde vem cada configuração

`config_valor('CHAVE')` (em `config/configuracoes.php`) devolve, nesta ordem:

1. o valor salvo no painel (tabela `configuracoes_sistema`, migração 090);
2. o valor do `.env` (ou da variável do servidor);
3. o padrão do sistema, o mesmo de antes desta etapa.

A chave do painel tem o **mesmo nome** da variável do `.env`. Apagar o campo no painel remove o valor salvo e o sistema volta ao `.env`. Aplicar a migração 090 não muda nada sozinho: a tabela começa vazia. Sem a tabela (migração não aplicada) ou com o banco fora do ar, tudo continua vindo do `.env`.

Só as chaves de `Biblioteca\Support\ConfiguracaoSistema::CAMPOS` podem vir do painel. Mesmo que alguém grave outra chave direto no banco (por exemplo `DB_NAME`), ela é ignorada.

| Grupo | Chaves | Quem altera |
| --- | --- | --- |
| Identificação | `ETIQUETA_BIBLIOTECA` | Administrador, Bibliotecária |
| Regras de empréstimo | `EMPRESTIMO_PRAZO_DIAS`, `EMPRESTIMO_RENOVACAO_DIAS`, `EMPRESTIMO_MAX_RENOVACOES`, `EMPRESTIMO_RENOVAR_ATRASADO`, `EMPRESTIMO_PRAZO_MAXIMO_DIAS`, `EMPRESTIMO_LIMITE_ALUNO` (novo), `EMPRESTIMO_BLOQUEAR_ATRASO` (novo) | Administrador, Bibliotecária |
| Cadastro de alunos | `ALUNOS_EMAIL_DOMINIO` | Administrador, Bibliotecária |
| Segurança do painel | `PAINEL_INATIVIDADE_MINUTOS` (10 a 480, padrão 120), `LOGIN_MAX_TENTATIVAS` (3 a 20, padrão 5) | Administrador |
| Site em manutenção | `SITE_MANUTENCAO`, `SITE_MANUTENCAO_MENSAGEM` | Administrador |

Ficam **só no `.env`** (técnicas; um valor errado tira o sistema do ar): `APP_ENV`, `APP_BASE_PATH`, `APP_TIMEZONE`, `DB_*`, `STORAGE_IMPORTACOES`, `CDD_*`. O administrador vê os valores em uso na própria página; a senha do banco aparece só como "definida (oculta)".

Os textos institucionais do site público continuam em **Sistema → Site público** (`site_configuracoes`, migração 080).

### Regras novas de empréstimo

As duas vêm **desligadas**, porque a escola ainda não aprovou um regulamento:

- **Limite de livros por aluno**: conta os empréstimos em aberto. É conferido dentro da transação, com a linha do aluno bloqueada (`FOR UPDATE`), então dois empréstimos simultâneos não passam do limite.
- **Bloqueio por atraso**: aluno com algum livro atrasado não pode fazer novo empréstimo.

A tela Novo empréstimo já avisa ao escolher o aluno. Empréstimos já registrados não mudam de data quando um prazo é alterado.

### Validação

Números fora da faixa, domínio com `@` no meio ou espaços, opções diferentes de sim/não e textos longos demais são recusados, e nada é gravado. O prazo padrão e os dias de renovação não podem passar do prazo máximo, considerando o valor que ficará em uso. Cada campo é conferido no servidor com a permissão dele: a bibliotecária não consegue ligar a manutenção nem mudar a segurança, mesmo forjando o formulário.

### Histórico

Cada alteração grava em `configuracoes_historico` a chave, o valor anterior, o novo valor (NULL = ".env / padrão"), quem alterou, o IP e a data. Gatilhos impedem editar ou apagar o histórico. A página mostra as 30 alterações mais recentes, e a ação também entra em `acoes_administrativas` (`configuracao.editar`), só com os nomes das chaves.

## Perfis e permissões

`admin/permissoes.php` mostra a matriz de `AUTH_PERMISSOES` com a descrição de cada permissão (`AUTH_PERMISSOES_DESCRICOES`, em `admin/includes/auth.php`). Todos os perfis podem abrir e veem o próprio perfil destacado; o administrador vê também quantas contas ativas há em cada perfil.

As permissões continuam fixas no código, de propósito: um ajuste por engano poderia expor dados pessoais dos alunos. Para dar mais ou menos acesso a uma pessoa, mude o perfil da conta em Administradores.

Permissões novas:

| Permissão | Perfis |
| --- | --- |
| `configuracoes.ver` | Administrador, Bibliotecária |
| `configuracoes.editar` | Administrador, Bibliotecária |
| `sistema.manutencao` | Administrador |

## Manutenção (só administrador)

- **Diagnóstico**: versão do PHP (8.2 ou mais nova), extensões `pdo_mysql`, `mbstring`, `zip` e `fileinfo`, `.env`, ambiente e `display_errors`, versão e codificação do banco, migrações pendentes, pastas graváveis, proteção `.htaccess` das planilhas, prazo provisório e site em manutenção.
- **Dados guardados**: totais de livros, exemplares, alunos, empréstimos, contas, importações, inventários e ações, e o tamanho do banco. Servem para conferir uma cópia restaurada.
- **Cópia de segurança**: baixa um `.sql` com estrutura, dados, views, procedimento e gatilhos (`src/Services/BackupService.php`). Exige confirmar que o arquivo, que tem dados pessoais e hashes de senha, será guardado em local protegido. Não tem `DROP TABLE`: restaurar sobre um banco com dados falha em vez de sobrescrever. Restaure sempre em um banco **vazio**: phpMyAdmin → Importar, ou `C:\xampp\mysql\bin\mysql.exe -u root -p banco_novo < copia.sql`. A geração entra em `acoes_administrativas` (`sistema.backup`).
- **Limpeza**: apaga tentativas de login com mais de 30 dias, que só servem para o bloqueio de 15 minutos. Nenhum outro dado é apagado (`sistema.limpeza`).

## Site em manutenção

Com `SITE_MANUTENCAO` ligado, todas as páginas do site público (`includes/layout.php`) respondem **503** com a mensagem configurada (escapada, com parágrafos e listas como nos textos do site) e um link "Acesso da equipe". O painel continua funcionando normalmente.

## Instalação

```bash
php database/migrar.php   # aplica a 090 (faça antes uma cópia de segurança)
```

## Testes

```bash
php admin/tests/configuracoes-teste.php [--manter]
```

São 96 verificações em um banco temporário com dados fictícios, que nunca usa o banco do `.env`. Cobrem a prioridade painel → `.env` → padrão, o funcionamento sem a migração, a validação, o histórico imutável, as regras novas de empréstimo, o tempo de sessão e o limite de tentativas, o acesso por perfil, CSRF, XSS e o site em manutenção. A cópia de segurança é **restaurada de verdade** em um segundo banco temporário, e o teste confere linha por linha das contagens, textos com aspas e barras, datas, gatilhos, views e procedimento. Por fim, confere que nenhum livro, exemplar, aluno, conta ou empréstimo foi alterado. A restauração usa o cliente `mysql` (no XAMPP, `C:\xampp\mysql\bin\mysql.exe`, que pode ser indicado em `MYSQL_CLIENTE`).
