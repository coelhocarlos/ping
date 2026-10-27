# Monitor de Ping IP • PHP + MySQL

Monitor de latência de hosts/IPs com sincronização centralizada em banco de dados MySQL,
feito para hospedagem em **Hostinger**. Interface moderna com tema Dark/Light e gráficos
interativos via Chart.js.

## Funcionalidades

- **Monitoramento de ping** de IPs e domínios diretamente pelo navegador
- **Sincronização em MySQL** — os hosts ficam salvos centralmente e visíveis em qualquer dispositivo
- **Gráfico de Latência Global** (Chart.js) — linha temporal com todos os hosts
- **Gráfico por Host** (Chart.js) — barras detalhadas ao clicar em um card, com mín/méd/máx
- **Mini-gráficos** nos cards com as últimas medições
- **Tema Dark/Light** com persistência em `localStorage`
- **Intervalo de verificação** configurável (5s a 5min)
- **API RESTful** embutida (`list`, `add`, `update`, `delete`, `clear`, `stats`)
- **Métricas em tempo real** — total, estáveis, alta latência e média geral

## Estrutura de arquivos

```
├── index.php            # Aplicação principal (API + interface)
├── config.php           # Credenciais do banco (NÃO versionado)
├── config.example.php   # Modelo para copiar para config.php
├── .htaccess            # Proteção de arquivos sensíveis e headers de segurança
├── .gitignore
└── README.md
```
#DATABASE

CREATE TABLE IF NOT EXISTS ping_hosts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    host VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

## Instalação no Servidor

1. **Crie o banco de dados MySQL** no hPanel da Hostinger.
2. **Copie o modelo de configuração:**
   ```bash
   cp config.example.php config.php
   ```
3. **Edite `config.php`** com as credenciais do banco e um token de admin seguro.
4. **Suba os arquivos** (`index.php`, `config.php`, `.htaccess`) para `public_html`.
   - A tabela `ping_hosts` é criada automaticamente no primeiro acesso.

## Segurança

- Credenciais ficam em `config.php`, protegido pelo `.htaccess` e ignorado pelo Git.
- Todas as queries usam **PDO com prepared statements**.
- Validação de entrada no servidor (tamanho, caracteres, duplicatas).
- Ação destrutiva `clear` exige **token de autorização**.
- Headers: `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`.

> **Importante:** altere `ADMIN_TOKEN` em `config.php` antes de publicar.

## Tecnologias

PHP (PDO/MySQL) • Tailwind CSS (CDN) • Chart.js 4 • Font Awesome • JavaScript (Fetch API)

## Observação sobre medição

O ping é medido pelo navegador (via `fetch`/`Image`), refletindo a latência
cliente → host. Não substitui um ping ICMP real de servidor para servidor.
