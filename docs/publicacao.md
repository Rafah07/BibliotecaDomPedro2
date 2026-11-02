# Publicação do sistema — requisitos e checklist de produção

Este guia prepara a futura hospedagem do sistema da Biblioteca Dom Pedro II. **Nada aqui foi publicado**: a escolha do domínio, da hospedagem e o momento da publicação dependem da autorização da escola.

## 1. Requisitos mínimos de hospedagem

| Item | Mínimo | Observação |
| --- | --- | --- |
| PHP | 8.2 (testado também no 8.3) | Extensões: `pdo_mysql`, `mbstring`, `zip`, `fileinfo`, `curl`, `gd` (capas), `dom`/`xml` (planilhas). **`intl` não é necessária** (testado sem ela). |
| Banco | MariaDB 10.4+ ou MySQL 8.0+ | `utf8mb4`, InnoDB, permissão para criar gatilhos e um procedimento (veja `log_bin_trust_function_creators` no README). |
| Servidor web | Apache 2.4 com `AllowOverride All` (lê os `.htaccess`) | Nginx ou IIS funcionam, mas exigem as regras da seção 4. |
| HTTPS | Obrigatório | Certificado válido (Let's Encrypt ou da hospedagem). |
| Disco | ~200 MB + capas + cópias de segurança | O banco com o acervo real tem poucas dezenas de MB. |
| Acesso | SSH ou terminal (para `php database/migrar.php`) | Sem terminal, as migrações podem ser aplicadas pelo phpMyAdmin, uma a uma, em ordem. |
| Cron (opcional) | Para cópias de segurança automáticas | Veja [`backup-recuperacao.md`](backup-recuperacao.md). |

Hospedagens compartilhadas sem Composer funcionam: envie a pasta `vendor/` gerada no computador da escola (`composer install --no-dev`).

## 2. Checklist de produção

Marque cada item antes de abrir o site ao público.

### Ambiente e configuração
- [ ] `.env` criado no servidor a partir do `.env.example`, **fora do Git**, com permissão de leitura só para o usuário do PHP (ex.: `chmod 640 .env`).
- [ ] `APP_ENV=production` (desliga as contas demonstrativas, esconde erros técnicos e mostra uma página genérica em erros inesperados).
- [ ] `APP_BASE_PATH` vazio (domínio próprio) ou com a subpasta (ex.: `/biblioteca`).
- [ ] `APP_HTTPS=true` **somente** se o HTTPS terminar num proxy/painel da hospedagem e o PHP receber a conexão sem HTTPS. Com o certificado no próprio Apache, deixe vazio: o sistema detecta sozinho.
- [ ] `APP_TIMEZONE=America/Sao_Paulo` (ou o fuso da escola).
- [ ] Usuário do banco **próprio** (nunca `root`), com senha forte, com acesso só ao banco da biblioteca.
- [ ] `php.ini`: `display_errors=Off`, `log_errors=On`, `expose_php=Off`, `session.cookie_httponly=1`, `upload_max_filesize` ≥ 10M e `post_max_size` ≥ 12M (planilhas).
- [ ] Prazo de empréstimo e demais regras definidos em **Sistema → Configurações** (hoje 14 dias é provisório).

### HTTPS e domínio
- [ ] Domínio apontando para a hospedagem (decisão e contratação: escola).
- [ ] Certificado instalado e renovação automática ativa.
- [ ] Redirecionamento de `http://` para `https://` (no painel da hospedagem ou com o bloco comentado no `.htaccess` da raiz).
- [ ] Conferir em **Sistema → Manutenção** que o diagnóstico não mostra itens vermelhos.
- [ ] Conferir no navegador que o cookie `BDPADMIN` aparece como *Secure* e *HttpOnly*.

### Arquivos e permissões
- [ ] Enviar só o necessário: **não** enviar `.git/`, `.env` de desenvolvimento, `admin/tests/`, `backups/`, planilhas `.xlsx/.csv` com dados de alunos, `*.sql` e logs.
- [ ] Pastas graváveis pelo PHP **apenas**: `storage/importacoes/` e `uploads/capas/` (ex.: `chmod 750` e dono = usuário do PHP). Todo o resto somente leitura (`644` para arquivos, `755` para pastas).
- [ ] Se possível, mover `storage/` para fora da pasta pública e apontar `STORAGE_IMPORTACOES` para o novo caminho.
- [ ] Rodar `php admin/tests/auditoria-seguranca-teste.php` com `TESTE_URL_APACHE=https://endereço/` **num servidor de homologação** (nunca com o banco real) e confirmar que `.env`, `.git`, `backups/`, `storage/`, `database/`, `config/`, `src/`, `docs/`, `admin/includes/`, `admin/tests/` e `vendor/` respondem 403/404.

### Banco de dados
- [ ] Cópia de segurança **antes** de qualquer mudança (Sistema → Manutenção).
- [ ] `php database/migrar.php --status` e depois `php database/migrar.php` (seção 3).
- [ ] Primeiro administrador criado com `php database/criar-admin.php` (senha ≥ 10 caracteres, com letras e números).
- [ ] Contas pessoais para cada funcionário (nada de conta compartilhada), com o menor perfil necessário.

### Operação
- [ ] Cópia de segurança diária guardada fora do servidor (seção 6 de [`backup-recuperacao.md`](backup-recuperacao.md)) e **restauração testada** em banco temporário.
- [ ] Logs do PHP e do servidor web acessíveis ao responsável técnico e com rotação.
- [ ] Responsável técnico definido para atualizações e incidentes.
- [ ] Decisões de privacidade da escola registradas ([`seguranca-lgpd.md`](seguranca-lgpd.md)).

## 3. Aplicação segura das migrações

1. Gere uma cópia de segurança (Sistema → Manutenção, ou `mysqldump`).
2. Confira o que falta: `php database/migrar.php --status`.
3. Aplique: `php database/migrar.php`. Cada arquivo roda uma única vez e fica registrado em `schema_migracoes`. As migrações só criam ou acrescentam estruturas; nenhuma apaga dados.
4. Abra Sistema → Manutenção e confira "Migrações do banco: todas aplicadas".
5. Se uma migração falhar: o sistema para naquele arquivo. Não edite migrações já aplicadas; corrija a causa (ex.: permissão de gatilho) e rode de novo. Em último caso, restaure a cópia num banco novo (nunca por cima do principal).

Esta etapa **não criou migrações novas** (a última continua sendo `100_etiquetas_codigo_escrito.sql`).

## 4. Proteção de diretórios privados em servidores sem Apache

O `.htaccess` só vale no Apache. Em outros servidores, reproduza estas regras.

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name biblioteca.escola.exemplo;
    root /var/www/biblioteca;
    index index.php;

    add_header X-Content-Type-Options nosniff always;
    add_header Referrer-Policy same-origin always;
    autoindex off;

    # Arquivos ocultos (.env, .git, .htaccess) e pastas internas: nunca públicos.
    location ~ /\.                     { deny all; return 404; }
    location ~ ^/(vendor|backups?|storage|database|config|src|docs|includes|admin/includes|admin/tests)(/|$) { deny all; return 404; }
    location ~* \.(sql|md|log|ini|bak|backup|old|orig|save|swp|tmp|dist|sh|bat|ps1|zip|rar|7z|tar|gz|tgz|csv|xls|xlsx|ods|sqlite|db|dump)$ { deny all; return 404; }
    location ~ ^/composer\.(json|lock)$ { deny all; return 404; }

    # Capas enviadas: só imagens, nunca scripts.
    location ^~ /uploads/capas/ {
        location ~* \.(jpe?g|png|webp)$ { try_files $uri =404; }
        deny all;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS on;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
server { listen 80; server_name biblioteca.escola.exemplo; return 301 https://$host$request_uri; }
```

### IIS (Windows Server) — `web.config` na raiz

```xml
<configuration>
  <system.webServer>
    <directoryBrowse enabled="false" />
    <security>
      <requestFiltering>
        <hiddenSegments>
          <add segment=".env" /><add segment=".git" /><add segment="vendor" /><add segment="backups" />
          <add segment="storage" /><add segment="database" /><add segment="config" /><add segment="src" />
          <add segment="docs" /><add segment="includes" /><add segment="tests" />
        </hiddenSegments>
        <fileExtensions allowUnlisted="true">
          <add fileExtension=".sql" allowed="false" /><add fileExtension=".md" allowed="false" />
          <add fileExtension=".log" allowed="false" /><add fileExtension=".csv" allowed="false" />
          <add fileExtension=".xlsx" allowed="false" /><add fileExtension=".bak" allowed="false" />
        </fileExtensions>
      </requestFiltering>
    </security>
  </system.webServer>
</configuration>
```

No IIS, a pasta `uploads/capas/` precisa de um `web.config` próprio removendo o manipulador do PHP (`<handlers><remove name="PHP_via_FastCGI" /></handlers>`).

> Atenção ao bloquear "includes": no Apache e no Nginx a regra cobre `/includes/` e `/admin/includes/`; não use o nome `vendor`, `src`, `config` ou `docs` para pastas que precisem ser públicas (a biblioteca Chart.js fica em `admin/assets/lib/` por isso).

## 5. Capas e uploads

- Capas: `uploads/capas/`, nome aleatório, só JPG/PNG/WebP até 2 MB, regravadas pelo GD (remove metadados). A pasta não executa scripts.
- Planilhas de importação do acervo: `storage/importacoes/`, privadas. Planilhas de **alunos** não são guardadas (são lidas e descartadas).
- Inclua `uploads/capas/` na cópia de arquivos (o banco guarda só o caminho da capa).

## 6. Logs e tratamento de erros

- Em produção, erros vão para o log do PHP (`error_log`) com o prefixo `[biblioteca]`. Na tela aparecem apenas mensagens genéricas.
- Os logs **não** devem ficar numa pasta pública; se a hospedagem gravar em `storage/logs/`, ela já é bloqueada.
- Senhas nunca são registradas; o registro de ações (`acoes_administrativas`) guarda quem fez o quê, sem dados pessoais dos alunos nas importações.

## 7. Atualização futura do sistema

1. Teste a nova versão primeiro numa cópia (homologação), com uma **cópia restaurada** do banco em banco temporário.
2. Rode todos os testes ([`testes.md`](testes.md)).
3. Em produção: cópia de segurança → ligue "Site em manutenção" (Sistema → Configurações) e avise a equipe para não usar o painel → substitua os arquivos (sem apagar `.env`, `uploads/capas/` e `storage/`) → `composer install --no-dev` → `php database/migrar.php` → confira o diagnóstico → tire o site da manutenção.
4. Guarde a versão anterior até confirmar que tudo funciona.

## 8. Ajustes obrigatórios antes da publicação (pendentes)

- Escola decidir domínio, hospedagem, responsável técnico e regras de empréstimo (prazo definitivo).
- Definir a política de acesso, retenção e descarte de dados pessoais ([`seguranca-lgpd.md`](seguranca-lgpd.md)).
- Fazer o inventário físico e cadastrar os exemplares reais (hoje o acervo é bibliográfico; disponibilidade só aparece com exemplares cadastrados).
- Importar os alunos reais **somente** no computador autorizado.
- Testar o sistema no XAMPP do computador da biblioteca com as páginas reais (o ambiente de testes desta etapa foi Linux).
