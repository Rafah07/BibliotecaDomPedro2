# Cópia de segurança e recuperação (XAMPP)

Este guia explica como gerar, guardar e **testar** a cópia de segurança do banco da Biblioteca Dom Pedro II. Regra principal: **nunca restaure por cima do banco principal**. A restauração é feita sempre num banco novo e vazio. O banco principal só é trocado depois que a cópia restaurada for conferida, e mesmo assim por decisão do responsável técnico.

## 1. O que a cópia contém

A cópia gerada em **Sistema → Manutenção → Cópia de segurança** (arquivo `.sql`, `src/Services/BackupService.php`) contém:

- a estrutura de todas as tabelas, com chaves estrangeiras, índices e regras `CHECK`;
- todos os dados: livros, exemplares, códigos BDP, localizações, alunos, empréstimos, renovações, inventários, histórico CDD, contas de acesso (com o **hash** das senhas, nunca a senha), configurações e registro de ações;
- as views, o procedimento `gerar_exemplares` e os gatilhos que protegem os históricos e os códigos BDP (gravados depois dos dados);
- a sequência dos códigos BDP (`exemplar_codigo_seq`), para que um código nunca seja reaproveitado depois da restauração.

Ela **não** contém `DROP TABLE` nem `DEFINER`: restaurar sobre um banco que já tem tabelas **falha** em vez de sobrescrever.

Desde esta etapa a cópia é gerada num **instantâneo consistente** do banco (`START TRANSACTION WITH CONSISTENT SNAPSHOT`). Um empréstimo registrado enquanto a cópia é baixada não sai pela metade no arquivo.

**A cópia não inclui as capas.** As imagens ficam em `uploads/capas/`, e o banco guarda só o nome do arquivo. Copie essa pasta junto (seção 4).

## 2. Gerar a cópia pelo painel

1. Entre como **Administrador** (só ele tem a permissão `sistema.manutencao`, que inclui a cópia de segurança).
2. Abra **Sistema → Manutenção**.
3. Em **Cópia de segurança**, marque a confirmação de que o arquivo será guardado em local protegido e clique em **Baixar cópia de segurança**.
4. Guarde o arquivo `biblioteca-dom-pedro-ii-AAAA-MM-DD-HHMMSS.sql` conforme a seção 5.

A geração fica registrada em `acoes_administrativas` (`sistema.backup`), com quem e quando.

## 3. Alternativa pela linha de comando (mysqldump)

Útil para cópias agendadas ou se o painel não abrir. No **Prompt de Comando** do Windows:

```bat
cd C:\xampp\mysql\bin
mysqldump.exe -u USUARIO -p --single-transaction --routines --triggers --skip-definer --default-character-set=utf8mb4 biblioteca_dom_pedro > D:\copias\biblioteca-2026-11-02.sql
```

- Troque `USUARIO` e o nome do banco pelos do seu `.env` (`DB_USER` e `DB_NAME`). A senha é pedida na tela; **não** escreva a senha no comando nem em arquivos `.bat`.
- `--single-transaction` garante a cópia consistente sem travar o sistema.
- Se o seu `mysqldump` não aceitar `--skip-definer` (versões antigas), remova a opção. Nesse caso a restauração em outro servidor pode exigir o mesmo usuário.

## 4. Copiar os arquivos que não estão no banco

Junto com o `.sql`, copie:

- `uploads/capas/` (capas dos livros);
- o `.env` **do servidor**, guardado à parte e com acesso restrito (contém a senha do banco).

Não é preciso copiar `storage/importacoes/`, a não ser que a escola queira manter as planilhas originais do acervo. Planilhas de alunos não ficam no servidor.

## 5. Onde guardar

- **Pelo menos duas cópias em lugares diferentes**: por exemplo, um pendrive ou HD externo guardado na secretaria e uma pasta da escola com acesso restrito.
- **Nunca** dentro da pasta do site (`C:\xampp\htdocs\...`), nunca no GitHub, nunca em e-mail pessoal ou serviço de nuvem particular.
- Se possível, compacte com senha (por exemplo, 7-Zip com AES-256) e guarde a senha separada do arquivo.
- O arquivo tem dados pessoais de alunos e hashes de senha: trate como documento sigiloso.
- Defina por quanto tempo guardar as cópias antigas e como descartá-las (decisão da escola, veja [`seguranca-lgpd.md`](seguranca-lgpd.md)). Sugestão: diárias por 2 semanas, semanais por 2 meses, mensais por 1 ano.

## 6. Cópia automática (agendada)

No Windows, use o **Agendador de Tarefas** para rodar um `.bat` com o `mysqldump` da seção 3 todos os dias, fora do horário de uso.

Para não deixar a senha no `.bat`, crie um arquivo de opções do MySQL **fora** da pasta do site, por exemplo `C:\biblioteca-backup\backup.cnf`, legível só pela conta do Windows que roda a tarefa:

```ini
[mysqldump]
user=USUARIO_BACKUP
password=SENHA_FORTE
```

E no `.bat`:

```bat
C:\xampp\mysql\bin\mysqldump.exe --defaults-extra-file=C:\biblioteca-backup\backup.cnf --single-transaction --routines --triggers --default-character-set=utf8mb4 biblioteca_dom_pedro > D:\copias\biblioteca-%date:~6,4%-%date:~3,2%-%date:~0,2%.sql
```

Use um usuário do banco só para cópias, com permissões `SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT` e acesso ao procedimento (`SHOW ROUTINE` no MariaDB 11 ou `SELECT` em `mysql.proc` nas versões anteriores).

Na hospedagem, o equivalente é uma tarefa **cron** com o mesmo `mysqldump`, gravando fora da pasta pública.

## 7. Testar a restauração (sem tocar no banco principal)

Faça isto pelo menos uma vez por mês e sempre antes de uma atualização.

### Pelo phpMyAdmin

1. Abra `http://localhost/phpmyadmin`.
2. **Novo** → crie um banco com outro nome, por exemplo `biblioteca_teste_restauracao`, com agrupamento `utf8mb4_unicode_ci`.
3. Selecione **esse banco novo** (confira o nome no topo da tela!) → **Importar** → escolha o `.sql` → **Executar**.
4. Se o arquivo for grande (> 40 MB), aumente `upload_max_filesize` e `post_max_size` no `php.ini` do XAMPP ou use o método abaixo.

### Pelo Prompt de Comando

```bat
cd C:\xampp\mysql\bin
mysql.exe -u USUARIO -p -e "CREATE DATABASE biblioteca_teste_restauracao CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql.exe -u USUARIO -p biblioteca_teste_restauracao < D:\copias\biblioteca-2026-11-02.sql
```

Se a importação reclamar de gatilhos ou procedimento (`log_bin_trust_function_creators`), veja o README, seção de requisitos.

### Conferir

1. Em **Sistema → Manutenção → Dados guardados**, anote os totais do banco principal (livros, exemplares, alunos, empréstimos, contas, inventários, ações).
2. No banco restaurado, conte as mesmas tabelas (phpMyAdmin mostra o número de linhas, ou rode `SELECT COUNT(*) FROM livros;` etc.). Os números devem bater com os da hora da cópia.
3. Confira que existem os gatilhos (`SHOW TRIGGERS;`), as views (`SHOW FULL TABLES WHERE Table_type='VIEW';`) e o procedimento (`SHOW PROCEDURE STATUS WHERE Db = DATABASE();`).
4. Confira a sequência dos códigos BDP: `SELECT ultimo FROM exemplar_codigo_seq;` deve ser igual à do banco principal (o próximo exemplar recebe o número seguinte).
5. **Apague o banco de teste** quando terminar (phpMyAdmin → banco de teste → Operações → Apagar o banco). Ele tem dados pessoais.

O teste automático `php admin/tests/backup-restauracao-teste.php` faz tudo isso com dados fictícios em bancos temporários: compara estrutura, contagens, `CHECKSUM`, chaves estrangeiras, índices, gatilhos, views, procedimento, regras `CHECK`, a sequência BDP e confere que restaurar duas vezes falha em vez de duplicar.

## 8. Recuperação depois de um problema real

Use apenas quando o banco principal estiver perdido ou corrompido, e com o responsável técnico.

1. **Pare o uso do sistema**: avise a equipe para não usar o painel e desligue o Apache no painel do XAMPP (a opção "Site em manutenção" de Sistema → Configurações fecha só o site público).
2. Se o banco antigo ainda abre, **não apague**: gere uma cópia dele do jeito que está (pode ser útil para recuperar o que foi feito depois da última cópia boa).
3. Restaure a última cópia boa **num banco novo** (seção 7), por exemplo `biblioteca_recuperado`, e confira os totais.
4. Copie de volta a pasta `uploads/capas/` da cópia de arquivos.
5. Aponte o sistema para o banco novo alterando **somente** `DB_NAME` no `.env`.
6. Rode `php database/migrar.php --status` (deve dizer que está tudo aplicado; se faltar algo, rode `php database/migrar.php`).
7. Abra **Sistema → Manutenção** e confira o diagnóstico e os totais. Teste um empréstimo e uma devolução com um exemplar real só depois de conferir.
8. Registre por escrito o que aconteceu, o que se perdeu (empréstimos feitos depois da cópia precisam ser lançados de novo a partir do papel) e avise a direção (veja "Incidentes" em [`seguranca-lgpd.md`](seguranca-lgpd.md)).
9. Só depois de alguns dias funcionando, e com uma cópia nova guardada, o banco antigo pode ser descartado.

## 9. O que o sistema não faz (de propósito)

- **Não há botão de restaurar no navegador.** Uma restauração errada pelo navegador poderia apagar o acervo e os empréstimos. A restauração é sempre manual, num banco novo, como acima.
- O sistema não envia cópias para serviços externos.
