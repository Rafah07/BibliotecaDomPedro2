# Publicação na Hostinger (hPanel) — domínio próprio

Passo a passo para publicar o sistema numa hospedagem compartilhada da Hostinger, com o site na raiz do
domínio (ex.: `https://bibliotecadompedro.com.br`). Complementa o checklist geral de
[`publicacao.md`](publicacao.md).

**Plano:** prefira **Premium ou Business** (têm acesso SSH, útil para conferir as migrações, e cópias de
segurança automáticas). O plano mais simples também funciona, mas sem SSH.

---

## 1. No computador da biblioteca (XAMPP), antes de enviar

1. Ligue o Apache e o MySQL no painel do XAMPP.
2. Aplique as migrações pendentes no banco local (faz a cópia de segurança antes, sozinho):
   ```
   C:\xampp\php\php.exe database/migrar.php --verificar
   C:\xampp\php\php.exe database/migrar.php
   ```
3. No painel, **Configurações → Manutenção e backups → Cópia de segurança**: baixe o `.sql` e clique em
   **Testar recuperação**. Esse `.sql` é o banco que vai para a Hostinger (já sem `DEFINER` nos gatilhos,
   o que evita erro de permissão na importação).
4. Revise **Administradores**: desative contas de teste e confira se cada conta é de uma pessoa da equipe,
   com senha forte (toda conta tem acesso total).
5. Monte o pacote do site (o script usa a lista de arquivos do Git: **faça o commit antes**, senão
   arquivos novos ficam de fora):
   ```
   C:\xampp\php\php.exe database/preparar-publicacao.php
   ```
   Ele cria `C:\xampp\publicacao_biblioteca\site` e `biblioteca-site-AAAA-MM-DD.zip`, sem `.env`, testes,
   documentação, backups e planilhas.

## 2. hPanel: domínio, SSL e PHP

1. **Domínios**: o domínio precisa estar apontado para a hospedagem (DNS da Hostinger).
2. **Segurança → SSL**: instale o SSL gratuito no domínio e ative **Forçar HTTPS**.
3. **Avançado → Configuração do PHP**:
   - Versão **8.2 ou mais nova**.
   - Extensões ligadas: `pdo_mysql`, `mbstring`, `fileinfo` e **`zip`** (sem `zip`, as exportações para
     Excel não funcionam).
   - Opções: `display_errors` **desligado**, `upload_max_filesize` ≥ 10M e `post_max_size` ≥ 12M.

## 3. Banco de dados

1. **Bancos de dados → Gerenciamento**: crie o banco e o usuário (a Hostinger usa nomes como
   `u123456789_biblioteca`). Anote **nome do banco, usuário e senha**. O usuário criado ali tem acesso só a
   esse banco — é o que o sistema precisa (nunca use outra conta).
2. Clique em **phpMyAdmin** ao lado do banco → **Importar** → escolha o `.sql` da seção 1 → **Executar**.
   - Se o arquivo passar do limite de envio, compacte-o em `.zip` ou `.gz` (o phpMyAdmin aceita) ou peça a
     importação pelo SSH (seção 6).
   - Terminada a importação, ainda no phpMyAdmin: marque **todas as tabelas** → no menu “Com marcados”,
     escolha **Analisar tabela**. Isso atualiza as estatísticas do banco e evita páginas lentas logo depois
     da importação.
   - Se aparecer erro ao criar **gatilhos** (*TRIGGER*) ou o **procedimento**, pare e abra um chamado no
     suporte da Hostinger pedindo permissão para criar gatilhos no seu banco: o sistema usa gatilhos para
     impedir que histórico e códigos BDP sejam apagados.

## 4. Arquivos do site

1. **Arquivos → Gerenciador de arquivos** → abra `public_html`.
2. Apague o arquivo de boas-vindas da Hostinger (`default.php` ou `index.html`), se existir.
3. Envie o `.zip` para `public_html` e **extraia ali mesmo**: as pastas `admin`, `biblioteca_php`,
   `coleta`, `config`, `src`… devem ficar **direto** em `public_html` (não numa subpasta). Depois apague o
   `.zip`.
4. Crie o arquivo **`.env`** em `public_html` (copie o `.env.example` e edite):
   ```
   APP_ENV=production
   APP_BASE_PATH=
   APP_HTTPS=false
   APP_TIMEZONE=America/Sao_Paulo
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=u123456789_biblioteca
   DB_USER=u123456789_biblioteca
   DB_PASSWORD=a senha criada no passo 3.1
   ```
   `APP_ENV=production` desliga as contas demonstrativas e esconde erros técnicos. `APP_HTTPS` fica `false`
   porque o certificado é da própria hospedagem.
5. Abra o `.htaccess` de `public_html` e **tire o `#`** das três linhas do bloco “Publicação com HTTPS”.
6. Permissões: as pastas `storage/importacoes`, `storage/logs`, `storage/backups` e `uploads/capas`
   precisam aceitar gravação (755 costuma bastar na Hostinger). O `.env` pode ficar em 644: o `.htaccess`
   bloqueia o acesso a ele pelo navegador.

## 5. Conferências depois de publicar

1. Abra `https://seu-dominio/` (site público) e `https://seu-dominio/admin/` (painel) e entre com a sua conta.
2. **Configurações → Diagnóstico do sistema**: nenhum item vermelho. Confira principalmente migrações,
   gatilhos de proteção, permissões das pastas, extensão `zip` e proteção pública.
3. Tente abrir `https://seu-dominio/.env` e `https://seu-dominio/config/app.php`: os dois **não podem**
   mostrar conteúdo (devem dar 403 ou 404).
4. No catálogo, pesquise um título **sem acento** (ex.: `memorias`) e confira se encontra “Memórias”.
5. Crie um link de coleta de teste num lote pequeno, abra no Chromebook da escola e faça um pré-cadastro.
6. Exporte um relatório para Excel (confirma a extensão `zip`).

## 6. SSH (Premium/Business), opcional

**Avançado → Acesso SSH** mostra o comando de acesso. Já no servidor:

```
cd domains/seu-dominio/public_html
php database/migrar.php --verificar
```

Deve mostrar **0 migrações pendentes**. Em atualizações futuras do sistema, envie os arquivos novos e rode
`php database/migrar.php` por aqui (ele faz a cópia de segurança antes).

## 7. Rotina

- **Cópias de segurança**: além das automáticas da Hostinger, baixe pelo painel uma cópia do banco toda
  semana (Configurações → Manutenção e backups) e guarde fora do servidor, em local protegido (tem dados
  pessoais de alunos).
- **Links das equipes**: crie com validade curta e, terminada a organização inicial, desligue em
  **Configurações → Aceitar os links das equipes**.
- **Contas**: desative a conta de quem sair da equipe no mesmo dia.
