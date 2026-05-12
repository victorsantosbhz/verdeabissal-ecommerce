# VerdeAbissal Ecommerce - Mercado Livre Integration

Este projeto é um e-commerce baseado em WordPress + WooCommerce com integração automática para sincronização de produtos e estoques com o Mercado Livre.

## Estrutura do Projeto

- `Dockerfile`: Configura o WordPress com o plugin WooCommerce pré-instalado.
- `docker-compose.yml`: Orquestra o container do WordPress e o Banco de Dados MySQL.
- `wordpress_data/`: Diretório montado em `/var/www/html` no container. **Todos os plugins (de terceiros e customizados) ficam em `wordpress_data/wp-content/plugins/`**.

### Plugins customizados desenvolvidos neste projeto

- `wordpress_data/wp-content/plugins/ml-sync/`: integração com a API do Mercado Livre.
- `wordpress_data/wp-content/plugins/va-product-order/`: ordenação de produtos por estoque & vendas.
- `wordpress_data/wp-content/plugins/verdeabissal-calc-aquario/`: calculadora de aquário (cortes do vidro, espessura, peso total).

> **Importante:** ao criar um plugin novo, basta colocá-lo em `wordpress_data/wp-content/plugins/<seu-plugin>/` — ele já fica disponível dentro do container automaticamente, sem precisar editar o `docker-compose.yml`.

## Recarregando o ambiente quando você cria/edita um plugin

Mudanças em arquivos PHP são pegas no ato pelo Apache (não precisa reiniciar). **Mas** quando você:
- adiciona um plugin novo;
- mexe no `docker-compose.yml`;
- mexe no `Dockerfile`;

…é preciso recriar o container para que o WordPress enxergue o novo conteúdo:

```bash
docker compose down
docker compose up -d --build
```

Se o plugin novo não aparece no painel mesmo após `up -d`, force a recriação:

```bash
docker compose up -d --force-recreate wordpress
```

## Como Começar

### 1. Requisitos
- Docker e Docker Compose instalados.
- Um domínio acessível via HTTPS (para Webhooks/OAuth). Recomendado: [ngrok](https://ngrok.com/).

### 2. Configuração
1. Copie o arquivo `.env.example` para `.env`:
   ```bash
   cp .env.example .env
   ```
2. Configure o `NGROK_AUTHTOKEN` no seu `.env`.
3. Configure as variáveis `ML_CLIENT_ID` e `ML_CLIENT_SECRET`.

### 3. Rodar o Ambiente
Suba os containers:
```bash
docker-compose up -d
```
O site estará disponível localmente em `http://verdeabissal.localhost`.

### 4. Acesso Público (Webhooks/OAuth)
Para que o Mercado Livre consiga se comunicar com seu servidor local:
1. Verifique a URL gerada pelo ngrok nos logs do container:
   ```bash
   docker logs verdeabissal_tunnel
   ```
2. Use essa URL (ex: `https://abcd-123.ngrok-free.app`) para atualizar o seu `ML_REDIRECT_URI` no `.env` e no painel do Mercado Livre.

### 4. Configuração Inicial do WordPress
1. Acesse `http://localhost:8000` e siga os passos de instalação do WordPress.
2. Ative o plugin **WooCommerce** e siga o assistente de configuração.
3. Ative o plugin **Mercado Livre Sync for WooCommerce** na área de plugins.

## Funcionalidades Planejadas

- [ ] **OAuth Flow**: Autenticação segura com a conta do Mercado Livre.
- [ ] **Importação**: Buscar produtos do ML e criar automaticamente no WooCommerce.
- [ ] **Sincronização WC -> ML**: Atualizar preço e estoque no ML ao editar no site.
- [ ] **Webhooks (Notificações)**: Atualizar estoque no site quando uma venda ocorrer no Mercado Livre.

## Webhooks e OAuth
Certifique-se de que o seu `ML_REDIRECT_URI` no painel do Mercado Livre aponte para:
`https://SEU-DOMINIO-NGROK/wp-json/ml-sync/v1/auth/callback`
