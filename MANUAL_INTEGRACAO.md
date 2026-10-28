# Manual de Integracao Tecnica: API de Integracoes & Automacao n8n (Sellex)

Este documento contem as especificacoes tecnicas, arquitetura, esquema de base de dados, catalogo de endpoints e guia passo a passo para a equipa de automacao desenvolver e conectar fluxos no n8n e agentes de atendimento inteligente as contas sociais (Meta / Instagram / Facebook / WhatsApp) dos lojistas da Sellex.

---

## 1. Visao Geral da Arquitetura

O modulo de integracoes da Sellex conecta as redes sociais dos lojistas ao motor de automacao (n8n), garantindo isolamento estrito entre lojas (multi-tenant), seguranca criptografica de tokens e idempotencia na entrega de mensagens.

```mermaid
flowchart LR
    subgraph Meta [Meta Platform]
        IG[Instagram Direct]
        FB[Facebook Messenger]
        COM[Comentarios / Feed]
    end

    subgraph SellexAPI [Sellex Integrations API]
        WH[POST /webhooks/meta]
        Norm[Normalizador de Eventos]
        DB[(MySQL Sellex)]
        Sec[AES-256 Secrets Engine]
    end

    subgraph N8N [Motor de Automacao n8n]
        InWH[Webhook n8n Receiver]
        Ctx[Consultar Contexto / Catalogo]
        AI[Agente IA / Regras de Negocio]
        Act[Disparar Resposta]
    end

    Meta -->|Eventos em Tempo Real| WH
    WH --> Norm
    Norm -->|Grava Evento| DB
    Norm -->|Despacha Evento Normalizado| InWH
    InWH --> Ctx
    Ctx -->|GET /n8n/stores/:id/context| SellexAPI
    Ctx -->|GET /n8n/stores/:id/products| SellexAPI
    Ctx --> AI
    AI --> Act
    Act -->|POST /n8n/actions/send-message| SellexAPI
    Act -->|POST /n8n/events/:id/result| SellexAPI
    SellexAPI -->|Graph API com Token da Loja| Meta
```

### Principais Responsabilidades
1. **Sellex Core API**: Valida webhooks da Meta com assinatura digital HMAC-SHA256, autentica lojistas, armazena com seguranca os tokens de pagina cifrados com AES-256-GCM, normaliza mensagens em um formato JSON padronizado e disponibiliza contexto de produtos e clientes.
2. **Motor n8n**: Recebe o evento ja normalizado, toma decisoes automatizadas (ex: FAQ, recomendacao de produtos, consulta de precos/stock, geracao de links de carrinho/checkout) e orquestra a resposta de volta ao cliente.

---

## 2. Ambientes, URLs Base e Autenticacao

### URLs Base

| Ambiente | URL Base da API de Integracoes | Webhook de Recepcao Meta |
| :--- | :--- | :--- |
| **Producao** | `https://api.sellex.ao/integrations/v1` | `https://api.sellex.ao/integrations/v1/webhooks/meta` |
| **Desenvolvimento Local** | `http://localhost/app-sellex/api/integrations` | `http://localhost/app-sellex/api/integrations/webhooks/meta` |

> [!NOTE]
> A API suporta nativamente o prefixo `/v1` (ex: `/integrations/v1/...`) bem como a raiz `/integrations/...`.

---

### Chaves de Servico e Autenticacao

Para que o n8n faca chamadas autorizadas a API da Sellex, deve enviar o token de servico configurado no sistema em cada requisicao HTTP.

#### 1. Header de Autenticacao do n8n (Obrigatorio em endpoints `/n8n/*`)
O n8n pode usar qualquer um dos dois cabecalhos abaixo:

```http
Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c
```
ou:
```http
X-N8N-Service-Token: sellex_n8n_live_sec_7a9f82bc19405d6e2e8c
```

#### 2. Variaveis de Ambiente do Sistema (.env no Servidor)
```env
# Token de servico compartilhado entre Sellex e n8n
N8N_SERVICE_TOKEN=sellex_n8n_live_sec_7a9f82bc19405d6e2e8c

# URL do Webhook no n8n para onde a Sellex despacha os eventos recebidos
N8N_WEBHOOK_URL=https://automation.sellex.ao/webhook/sellex-social-events

# Credenciais da Aplicacao Meta (Facebook Developer App)
META_APP_ID=seu_meta_app_id
META_APP_SECRET=seu_meta_app_secret
META_WEBHOOK_VERIFY_TOKEN=sellex_meta_webhook_verify_token_2026

# Chave mestra de criptografia (32 bytes em hexadecimal para AES-256-GCM)
APP_ENCRYPTION_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

---

## 3. Esquema da Base de Dados

O modulo de integracoes utiliza 4 tabelas dedicadas no banco de dados MySQL (`utf8mb4`).

### Tabela 1: `social_connections`
Armazena as contas e paginas vinculadas por cada loja.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | Identificador unico da conexao. |
| `store_id` | INT (FK stores.id) | ID da loja proprietaria da conexao. |
| `provider` | VARCHAR(30) | Provedor: `facebook`, `instagram` ou `whatsapp`. |
| `external_account_id` | VARCHAR(120) | ID externo da pagina ou conta profissional na Meta. |
| `account_name` | VARCHAR(150) | Nome publico da pagina comercial ou @ do Instagram. |
| `connection_status` | VARCHAR(30) | Estado: `connected`, `disconnected`, `expired`, `revoked`. |
| `granted_scopes` | TEXT | Permissoes concedidas pela loja na Meta. |
| `access_token_encrypted` | TEXT | Token de longa duracao cifrado com AES-256-GCM. |
| `token_expires_at` | DATETIME | Data de expiracao do token da Meta. |
| `created_at` / `updated_at` | TIMESTAMP | Auditoria temporal. |

### Tabela 2: `social_events`
Fila e historico de auditoria de todos os eventos recebidos da Meta e despachados para o n8n.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | ID sequencial interno. |
| `event_id` | VARCHAR(64) UNIQUE | Identificador unico do evento no formato `evt_...`. |
| `store_id` | INT | Loja associada a conta que recebeu o evento. |
| `connection_id` | INT | ID da conexao em `social_connections`. |
| `provider` | VARCHAR(30) | `instagram`, `facebook` ou `whatsapp`. |
| `event_type` | VARCHAR(60) | Tipo: `social.message.received`, `social.comment.received`. |
| `external_event_id` | VARCHAR(120) | ID original da mensagem ou comentario na Meta (`mid`, `comment_id`). |
| `payload_json` | JSON | Payload completo normalizado para consumo no n8n. |
| `processing_status` | VARCHAR(30) | `pending`, `forwarded`, `processed`, `failed`, `ignored`. |
| `attempt_count` | INT | Numero de tentativas de despacho ao n8n. |
| `last_error` | TEXT | Descricao do erro em caso de falha. |
| `received_at` / `processed_at` | TIMESTAMP / DATETIME | Marcadores temporais de recepcao e conclusao. |

### Tabela 3: `social_conversations`
Mapeia o contato entre um cliente de rede social e a loja.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | ID interno da conversa. |
| `store_id` | INT | ID da loja. |
| `connection_id` | INT | ID da conexao social. |
| `provider` | VARCHAR(30) | Canal de comunicacao. |
| `external_conversation_id` | VARCHAR(120) | Identificador da thread ou conversa na Meta. |
| `external_customer_id` | VARCHAR(120) | Identificador do cliente (PSID no Messenger / IGSID no Instagram). |
| `customer_name` | VARCHAR(150) | Nome visivel do cliente externo. |
| `last_message_at` | TIMESTAMP | Data/hora da ultima mensagem trocada. |
| `conversation_status` | VARCHAR(30) | `open`, `closed`, `pending_human`. |

### Tabela 4: `social_outbound_messages`
Registo de auditoria de todas as mensagens que o n8n ou o lojista envia de volta ao cliente.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | ID sequencial. |
| `store_id` | INT | ID da loja. |
| `connection_id` | INT | ID da conexao social da loja. |
| `conversation_id` | INT (NULL) | ID da conversa relacionada. |
| `idempotency_key` | VARCHAR(64) UNIQUE | Chave de idempotencia para prevenir duplicidade de envio. |
| `recipient_external_id` | VARCHAR(120) | PSID ou IGSID do cliente destinatario. |
| `message_text` | TEXT | Conteudo do texto enviado. |
| `status` | VARCHAR(30) | `queued`, `sent`, `failed`, `rejected`. |
| `external_message_id` | VARCHAR(120) | ID retornado pela Graph API da Meta apos o envio. |
| `error_code` / `error_message` | VARCHAR(60) / TEXT | Detalhes de eventuais falhas reportadas pela Meta. |
| `created_at` / `sent_at` | TIMESTAMP / DATETIME | Auditoria temporal de envio. |

---

## 4. Catalogo Completo de Endpoints

### 4.1. Verificacao de Saude do Servico

#### GET `/health`
- **Descricao**: Verifica se o servico de integracoes esta operacional.
- **Autenticacao**: Publica.
- **Resposta**:
```json
{
  "success": true,
  "data": {
    "service": "sellex-integrations",
    "version": "v1",
    "status": "ok"
  }
}
```

#### GET `/ready`
- **Descricao**: Valida prontidao e conectividade com a base de dados.
- **Autenticacao**: Publica.
- **Resposta**:
```json
{
  "success": true,
  "data": {
    "service": "sellex-integrations",
    "version": "v1",
    "database": "connected",
    "status": "ready"
  }
}
```

---

### 4.2. Webhooks da Meta (Instagram / Facebook / WhatsApp)

#### GET `/webhooks/meta`
- **Descricao**: Endpoint de confirmacao de desafio (challenge) da Meta para validacao do webhook no painel de desenvolvedor.
- **Parametros Query**: `hub.mode=subscribe`, `hub.challenge=<CODE>`, `hub.verify_token=sellex_meta_webhook_verify_token_2026`.
- **Resposta**: Devolve o valor do desafio em texto puro (`text/plain; 200 OK`).

#### POST `/webhooks/meta`
- **Descricao**: Recebe notificacoes em tempo real da Meta com assinatura criptografica no header `X-Hub-Signature-256`.
- **Acao**: Valida o HMAC com `META_APP_SECRET`, normaliza os dados, grava na tabela `social_events` e repassa o payload para o n8n.
- **Resposta**: `200 OK` com estatisticas do lote recebido.

---

### 4.3. Endpoints de Consumo do n8n (Protegidos)

Todos os endpoints abaixo exigem o header:
`Authorization: Bearer <N8N_SERVICE_TOKEN>` ou `X-N8N-Service-Token: <N8N_SERVICE_TOKEN>`.

---

#### A. Obter Contexto Completo da Loja
Permite que o agente de IA compreenda quem e a loja, regras de frete, contactos e localizacao.

- **Metodo**: `GET`
- **Rota**: `/n8n/stores/{store_id}/context`
- **Parametros de URL**: `{store_id}` (inteiro, ex: `16`).
- **Exemplo de Requisicao**:
```http
GET /integrations/v1/n8n/stores/16/context HTTP/1.1
Host: api.sellex.ao
Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c
```
- **Exemplo de Resposta**:
```json
{
  "success": true,
  "data": {
    "store_id": 16,
    "name": "Kina Code Store",
    "slug": "kinacode",
    "seller": "Kina Mukumba",
    "support_whatsapp": "244923000000",
    "email": "kinamukumba@gmail.com",
    "location": "Luanda, Talatona",
    "description": "Venda de acessorios eletronicos e gadgets premium.",
    "segment": "Tecnologia",
    "activity": "Comercio a Retalho"
  }
}
```

---

#### B. Consultar Catalogo e Stock de Produtos
Permite que o chatbot consulte produtos da loja por palavra-chave ou categoria, sempre filtrando apenas itens com status `active`.

- **Metodo**: `GET`
- **Rota**: `/n8n/stores/{store_id}/products`
- **Parametros Query Opcionais**:
  - `q`: Termo de pesquisa no nome ou descricao (ex: `?q=fone`).
  - `category`: Filtrar por categoria exata (ex: `?category=Audio`).
- **Exemplo de Requisicao**:
```http
GET /integrations/v1/n8n/stores/16/products?q=fone HTTP/1.1
Host: api.sellex.ao
Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c
```
- **Exemplo de Resposta**:
```json
{
  "success": true,
  "data": {
    "store_id": 16,
    "total": 1,
    "products": [
      {
        "id": 42,
        "name": "Fone Bluetooth Noise Cancelling",
        "price": 28500.00,
        "discount_percentage": 10,
        "category": "Audio",
        "stock": 8,
        "in_stock": true,
        "description": "Fone sem fio com cancelamento ativo de ruido e bateria de 30 horas.",
        "image": "uploads/products/fone_bluetooth.jpg",
        "is_highlight": true
      }
    ]
  }
}
```

---

#### C. Consultar Historico do Cliente na Loja
Permite verificar se o cliente ja fez compras e seu volume de gastos.

- **Metodo**: `GET`
- **Rota**: `/n8n/stores/{store_id}/customers/{customer_id}`
- **Parametros de URL**:
  - `{store_id}`: ID da loja.
  - `{customer_id}`: ID numerico do cliente no Sellex CRM ou telefone com DDI (ex: `244923000000`).
- **Exemplo de Resposta**:
```json
{
  "success": true,
  "data": {
    "customer_id": 104,
    "name": "Carlos Silva",
    "phone": "244923112233",
    "total_orders": 3,
    "total_spent": 85000.00,
    "last_order_date": "24-10-2026"
  }
}
```

---

#### D. Enviar Mensagem de Resposta ao Cliente
Utilizado pelo n8n para responder ao cliente na rede social atraves da Graph API da Meta, usando o token autenticado e decifrado da loja.

- **Metodo**: `POST`
- **Rota**: `/n8n/actions/send-message`
- **Body JSON**:
```json
{
  "store_id": 16,
  "connection_id": 4,
  "recipient_external_id": "847291049281749",
  "message_text": "Ola Carlos! Temos o Fone Bluetooth em stock por Kz 28.500,00. Gostaria que gerasse o link de compra com entrega imediata?",
  "idempotency_key": "msg_evt_16_4_847291049281749_step1"
}
```
- **Campos Obrigatorios**:
  - `store_id`: ID da loja.
  - `connection_id`: ID da conexao social obtido no evento de entrada.
  - `recipient_external_id`: O ID do cliente na Meta (`customer.external_id`).
  - `message_text`: O texto da resposta a ser enviado.
  - `idempotency_key` *(opcional mas altamente recomendado)*: Chave unica para garantir que a mensagem nunca seja enviada duas vezes se o workflow rodar de novo.
- **Exemplo de Resposta de Sucesso**:
```json
{
  "success": true,
  "data": {
    "store_id": 16,
    "connection_id": 4,
    "recipient_external_id": "847291049281749",
    "status": "sent",
    "external_message_id": "m_mid.$cAAAAGaX1..."
  }
}
```

---

#### E. Registar Resultado do Workflow
Notifica a Sellex de que o n8n concluiu o processamento do evento, atualizando a auditoria e o painel do lojista.

- **Metodo**: `POST`
- **Rota**: `/n8n/events/{event_id}/result`
- **Parametros de URL**: `{event_id}` (o ID gerado pela Sellex recebido no webhook, ex: `evt_7a9f82bc19405d6e2e8c`).
- **Body JSON**:
```json
{
  "status": "processed",
  "error": null
}
```
*(Em caso de erro no workflow)*:
```json
{
  "status": "failed",
  "error": "Timeout ao consultar modelo de linguagem ou falha na resposta externa."
}
```
- **Exemplo de Resposta**:
```json
{
  "success": true,
  "data": {
    "event_id": "evt_7a9f82bc19405d6e2e8c",
    "status": "processed",
    "updated": true
  }
}
```

---

## 5. Estrutura do Payload Normalizado Recebido pelo n8n

Quando uma mensagem ou comentario chega no Instagram ou Messenger, a API da Sellex envia para o Webhook do n8n (`N8N_WEBHOOK_URL`) um payload estruturado neste padrao:

### Evento: `social.message.received` (Mensagem Direta / Direct)
```json
{
  "event_id": "evt_4b2c189e701a5d3f8290",
  "event_type": "social.message.received",
  "version": "1.0",
  "occurred_at": "2026-10-28T20:15:30+01:00",
  "store_id": 16,
  "channel": "instagram",
  "connection_id": 4,
  "external_account_id": "17841405309214488",
  "conversation_id": "conv_17841405309214488_847291049281749",
  "customer": {
    "external_id": "847291049281749",
    "display_name": "Cliente 1749"
  },
  "message": {
    "external_message_id": "mid.$cAAAb123456789",
    "text": "Ola, voces tem o fone bluetooth disponivel para entrega hoje?"
  },
  "metadata": {
    "source": "meta_webhook",
    "object": "instagram"
  }
}
```

### Evento: `social.comment.received` (Comentario em Publicacao)
```json
{
  "event_id": "evt_9d1f341a024c8e7b9911",
  "event_type": "social.comment.received",
  "version": "1.0",
  "occurred_at": "2026-10-28T20:16:00+01:00",
  "store_id": 16,
  "channel": "instagram",
  "connection_id": 4,
  "external_account_id": "17841405309214488",
  "conversation_id": "comment_1799201928371029",
  "customer": {
    "external_id": "99281726354",
    "display_name": "Maria Antunes"
  },
  "message": {
    "external_message_id": "1799201928371029",
    "text": "Qual e o preco deste tenis?"
  },
  "metadata": {
    "source": "meta_webhook",
    "field": "comments"
  }
}
```

---

## 6. Guia Pratico para Construcao do Workflow no n8n

A equipa de automacao deve configurar no n8n um workflow principal seguindo os passos abaixo:

```mermaid
sequenceDiagram
    autonumber
    participant Sellex as Sellex API
    participant n8n as n8n Workflow
    participant LLM as Modelo IA (OpenAI / Claude)
    participant Client as Cliente (Instagram/Messenger)

    Sellex->>n8n: POST no Webhook com payload normalizado
    Note over n8n: 1. Valida se store_id > 0
    n8n->>n8n: Responde 200 OK imediatamente para a Sellex
    n8n->>Sellex: GET /n8n/stores/:id/context (Obtem dados da loja)
    n8n->>Sellex: GET /n8n/stores/:id/products?q=... (Busca produtos)
    n8n->>LLM: Monta Prompt com Contexto + Catalogo + Pergunta do Cliente
    LLM-->>n8n: Resposta amigavel gerada
    n8n->>Sellex: POST /n8n/actions/send-message (Envia resposta)
    Sellex-->>Client: Mensagem entregue no Direct do Cliente
    n8n->>Sellex: POST /n8n/events/:id/result (Marca evento como processed)
```

### Passo 1: Criar a Credencial no n8n
1. No n8n, aceda a **Credentials** > **Add Credential** > **Header Auth**.
2. Nome da credencial: `Sellex Integrations API Key`.
3. Nome do Header: `Authorization`.
4. Valor: `Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c`.

### Passo 2: No Webhook de Entrada (Node 1)
- **Tipo de No**: `Webhook`
- **Metodo**: `POST`
- **Path**: `sellex-social-events` (ou o configurado em `N8N_WEBHOOK_URL`)
- **Response Mode**: `Using 'Respond to Webhook' Node` ou `On Received`.

### Passo 3: Confirmar Recepcao Imediata (Node 2)
- **Tipo de No**: `Respond to Webhook`
- **Status**: `200`
- **Body**: `{"status": "received", "event_id": "={{ $json.event_id }}"}`

### Passo 4: Buscar Contexto e Catalogo (Nodes 3 e 4)
- **Tipo de No**: `HTTP Request`
- **Metodo**: `GET`
- **URL**: `https://api.sellex.ao/integrations/v1/n8n/stores/{{ $('Webhook').item.json.store_id }}/context`
- **Authentication**: `Predefined Credential Type` > `Header Auth` (usar a credencial criada no Passo 1).

### Passo 5: Processamento Inteligente (Node 5)
- Encaminhar para o agente de IA (`AI Agent` ou `OpenAI / Claude / Gemini Node`).
- Injetar como contexto do sistema:
  - O nome da loja, contacto e regras obtidos no Passo 4.
  - A lista de produtos relevantes obtidos da busca de produtos.
  - A pergunta enviada pelo cliente em `$json.message.text`.

### Passo 6: Despacho da Resposta (Node 6)
- **Tipo de No**: `HTTP Request`
- **Metodo**: `POST`
- **URL**: `https://api.sellex.ao/integrations/v1/n8n/actions/send-message`
- **Authentication**: `Header Auth`.
- **Body**:
```json
{
  "store_id": "={{ $('Webhook').item.json.store_id }}",
  "connection_id": "={{ $('Webhook').item.json.connection_id }}",
  "recipient_external_id": "={{ $('Webhook').item.json.customer.external_id }}",
  "message_text": "={{ $json.ai_response_text }}",
  "idempotency_key": "={{ 'resp_' + $('Webhook').item.json.event_id }}"
}
```

### Passo 7: Finalizar Status do Evento (Node 7)
- **Tipo de No**: `HTTP Request`
- **Metodo**: `POST`
- **URL**: `https://api.sellex.ao/integrations/v1/n8n/events/{{ $('Webhook').item.json.event_id }}/result`
- **Body**:
```json
{
  "status": "processed",
  "error": null
}
```

---

## 7. Testes e Validacao em Tempo Real

### Teste 1: Test Ping pelo Painel do Vendedor
No dashboard do vendedor da Sellex, em **Integracoes**, cada ligacao possui o botao **"Testar em tempo real"**.
- Ao clicar, o frontend dispara uma chamada para `POST /meta/test-ping`.
- A API cria um evento sintetico com ID `evt_test_...` e dispara para o Webhook configurado no n8n.
- A equipa de automacao podera ver a execucao chegar imediatamente na aba **Executions** do n8n.

### Teste 2: Simulacao Manual via cURL
Para testar os endpoints sem depender de redes sociais externas, use o seguinte comando:

```bash
# 1. Testar consulta de contexto da loja 16
curl -X GET "https://api.sellex.ao/integrations/v1/n8n/stores/16/context" \
     -H "Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c"

# 2. Testar busca de produtos da loja 16
curl -X GET "https://api.sellex.ao/integrations/v1/n8n/stores/16/products?q=fone" \
     -H "Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c"

# 3. Testar envio de evento manual
curl -X POST "https://api.sellex.ao/integrations/v1/n8n/events" \
     -H "Content-Type: application/json" \
     -H "Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c" \
     -d '{
       "store_id": 16,
       "channel": "instagram",
       "event_type": "social.message.received",
       "customer": { "external_id": "123456", "display_name": "Teste n8n" },
       "message": { "external_message_id": "mid_test_1", "text": "Teste de integracao n8n" }
     }'
```

---

## 8. Boas Praticas e Recomendacoes Tecnicas
1. **Idempotencia Obrigatoria**: Envie sempre o campo `idempotency_key` no endpoint `/n8n/actions/send-message` para evitar disparar a mesma mensagem duas vezes caso ocorra retry de rede.
2. **Timeout e Resposta Assincrona**: O n8n deve sempre responder `200 OK` logo no inicio do workflow para a chamada de webhook recebida, processando os passos de IA e consulta de catalogo em segundo plano.
3. **Respeito ao Isolamento de Loja**: O `store_id` recebido no payload de entrada deve ser repassado inalterado a todas as chamadas de contexto e produtos para garantir seguranca absoluta entre lojistas.
4. **Tratamento de Excecoes**: Sempre adicione um no de `Error Trigger` no workflow do n8n que chame `POST /n8n/events/{event_id}/result` com `"status": "failed"` e a descricao do erro, garantindo que eventos falhados possam ser monitorados ou reprocessados pelo cron da Sellex.
