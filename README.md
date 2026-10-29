# Sellex — Ecossistema de gestão inteligente para pequenos negócioa

Manual Tecnico Central da Plataforma Sellex, documentando a Arquitetura Global, a API de Integracoes & Automacao n8n e o Sistema de Funil de Vendas das Redes Sociais.

---

## Indice Geral

1. [Visao Geral da Plataforma](#1-visao-geral-da-plataforma)
2. [Arquitetura Global do Sistema](#2-arquitetura-global-do-sistema)
3. [Manual de Integracao Tecnica: API de Integracoes & Automacao n8n](#3-manual-de-integracao-tecnica-api-de-integracoes--automacao-n8n)
   - [3.1. Arquitetura e Fluxo de Dados](#31-arquitetura-e-fluxo-de-dados)
   - [3.2. Ambientes, URLs Base e Autenticacao](#32-ambientes-urls-base-e-autenticacao)
   - [3.3. Esquema da Base de Dados](#33-esquema-da-base-de-dados)
   - [3.4. Catalogo Completo de Endpoints](#34-catalogo-completo-de-endpoints)
   - [3.5. Estrutura do Payload Normalizado](#35-estrutura-do-payload-normalizado)
   - [3.6. Guia Passo a Passo para Fluxos no n8n](#36-guia-passo-a-passo-para-fluxos-no-n8n)
   - [3.7. Testes e Validacao em Tempo Real](#37-testes-e-validacao-em-tempo-real)
4. [Sistema do Funil de Vendas de Redes Sociais](#4-sistema-do-funil-de-vendas-de-redes-sociais)
   - [4.1. Filosofia: De Interacao a Faturacao Autonoma 24h](#41-filosofia-de-interacao-a-faturacao-autonoma-24h)
   - [4.2. Ingestao e Deteccao de Eventos Sociais](#42-ingestao-e-deteccao-de-eventos-sociais)
   - [4.3. Motor de Resposta Automatica e Checkout](#43-motor-de-resposta-automatica-e-checkout)
   - [4.4. Organizacao e Etiquetagem de Leads](#44-organizacao-e-etiquetagem-de-leads)
   - [4.5. Metrificacao, Pontuacao e Ranking de Proximidade](#45-metrificacao-pontuacao-e-ranking-de-proximidade)
   - [4.6. Central Hibrida de Atendimento: Live Chat & Inbox](#46-central-hibrida-de-atendimento-live-chat--inbox)
5. [Guia de Implantacao e Seguranca](#5-guia-de-implantacao-e-seguranca)

---

## 1. Visao Geral da Plataforma

A Sellex e uma solucao integrada de comercio digital desenvolvida para conectar vendedores, catalogos de produtos e clientes finais em Angola e mercados emergentes.

### Pilares Fundamentais:
- **Painel do Vendedor (`/seller/`):** Gestao de catalogo de produtos, pedidos em tempo real, controle de carteira de vendas (saques, custodia e taxas) e CRM de clientes da loja.
- **Painel do Cliente (`/shop/`):** Acompanhamento de encomendas, historico de compras, rastreamento de entregas e validacao de codigo secreto.
- **Vitrine Digital (`/shop/catalog/`):** Catalogo responsivo de produtos da loja com adicao ao carrinho, selecao de metodos de pagamento (Multicaixa Express, Transferencia Bancaria e Pagamento na Entrega) e checkout rapido.
- **Autenticacao Segura OTP (`/auth/`):** Verificacao estrita via codigo OTP enviado por e-mail e SMS, com protecao absoluta contra exposicao in-app.
- **API Core (`/api/api-app/`):** Backend modular em PHP RESTful com base de dados relacional MySQL e cache Redis.
- **API de Integracoes (`/api/integrations/`):** Gateway de conexao com a Meta Platform (Facebook, Instagram e Messenger) acoplado ao motor de automacao n8n.

---

## 2. Arquitetura Global do Sistema

```mermaid
flowchart TD
    subgraph Canais_Externos [Canais Externos]
        IG[Instagram Direct / Reels / Comentarios]
        FB[Facebook Messenger / Paginas]
        WA[WhatsApp / Atendimento]
    end

    subgraph Sellex_Gateway [Gateway Sellex Integrations]
        WH[POST /webhooks/meta]
        SEC[Motor Criptografico AES-256-GCM]
        NORM[Normalizador de Payloads Sociais]
    end

    subgraph Automacao_n8n [Orquestrador n8n]
        FLOW[Workflow Central de IA]
        DEC[Classificador de Intencoes]
        GEN[Gerador de Resposta no Tom da Loja]
    end

    subgraph Core_Sellex [Core Plataforma Sellex]
        API[Core API RESTful]
        DB[(Base de Dados MySQL)]
        CRM[Modulo CRM & Funil de Vendas]
        ORD[Gestao de Pedidos & Checkout]
    end

    Canais_Externos -->|Webhooks Meta em Tempo Real| WH
    WH --> NORM
    NORM -->|Cifra e Valida Tokens| SEC
    NORM -->|Evento Normalizado| FLOW
    FLOW --> DEC
    DEC -->|Consulta Contexto e Produtos| API
    API --> DB
    DEC --> GEN
    GEN -->|POST /n8n/actions/send-message| API
    API -->|Entrega Direta no Chat do Cliente| Canais_Externos
    API -->|Atualiza Leads e Metricas| CRM
    API -->|Novo Pedido Gerado no Checkout| ORD
```

---

## 3. Manual de Integracao Tecnica: API de Integracoes & Automacao n8n

### 3.1. Arquitetura e Fluxo de Dados

O modulo de integracoes atua como uma ponte segura entre a Meta Platform (Graph API v20.0+) e o motor de automacao n8n:

1. **Sellex Core API**: Valida webhooks da Meta atraves de assinatura digital HMAC-SHA256, mantem os tokens de longa duracao das paginas criptografados com AES-256-GCM, normaliza eventos num formato JSON estandardizado e fornece endpoints de consulta de produtos e contexto de loja.
2. **Motor n8n**: Recebe o evento normalizado, executa a arvore de decisoes ou chamada a modelos de linguagem (LLM), consulta o catalogo da loja e despacha a resposta final atraves da Sellex.

---

### 3.2. Ambientes, URLs Base e Autenticacao

#### URLs Base

| Ambiente | URL Base da API de Integracoes | Endpoint de Webhook Meta |
| :--- | :--- | :--- |
| **Producao** | `https://api.sellex.ao/integrations/v1` | `https://api.sellex.ao/integrations/v1/webhooks/meta` |
| **Desenvolvimento** | `http://localhost/app-sellex/api/integrations` | `http://localhost/app-sellex/api/integrations/webhooks/meta` |

#### Cabecalho de Autenticacao do n8n (Obrigatorio em `/n8n/*`)

Toda chamada originada do n8n para a Sellex deve fornecer a credencial configurada no servidor em um dos dois cabecalhos:

```http
Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c
```
ou:
```http
X-N8N-Service-Token: sellex_n8n_live_sec_7a9f82bc19405d6e2e8c
```

#### Variaveis de Ambiente do Servidor (`.env`)

```env
# Token compartilhado entre Sellex e n8n
N8N_SERVICE_TOKEN=sellex_n8n_live_sec_7a9f82bc19405d6e2e8c

# URL do Webhook no n8n que recebe eventos despachados pela Sellex
N8N_WEBHOOK_URL=https://automation.sellex.ao/webhook/sellex-social-events

# Credenciais da Aplicacao Meta (Facebook for Developers)
META_APP_ID=seu_meta_app_id
META_APP_SECRET=seu_meta_app_secret
META_WEBHOOK_VERIFY_TOKEN=sellex_meta_webhook_verify_token_2026

# Chave de criptografia de tokens (32 bytes em hexadecimal para AES-256-GCM)
APP_ENCRYPTION_KEY=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
```

---

### 3.3. Esquema da Base de Dados

O modulo opera sobre 4 tabelas relacionais com charset `utf8mb4`:

#### 1. `social_connections`
Armazena contas sociais vinculadas por cada lojista.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | Identificador unico da ligacao. |
| `store_id` | INT (FK stores.id) | Identificador da loja proprietaria. |
| `provider` | VARCHAR(30) | `facebook`, `instagram` ou `whatsapp`. |
| `external_account_id` | VARCHAR(120) | ID da pagina ou conta profissional na Meta. |
| `account_name` | VARCHAR(150) | Nome publico da pagina ou @ do perfil. |
| `connection_status` | VARCHAR(30) | `connected`, `disconnected`, `expired`, `revoked`. |
| `granted_scopes` | TEXT | Permissoes autorizadas. |
| `access_token_encrypted` | TEXT | Token de longa duracao cifrado com AES-256-GCM. |
| `token_expires_at` | DATETIME | Data de expiracao do token da Meta. |
| `created_at` / `updated_at` | TIMESTAMP | Auditoria temporal. |

#### 2. `social_events`
Fila e historico de auditoria de eventos recebidos e despachados ao n8n.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | ID sequencial. |
| `event_id` | VARCHAR(64) UNIQUE | Identificador unico no formato `evt_...`. |
| `store_id` | INT | Loja associada ao evento. |
| `connection_id` | INT | ID em `social_connections`. |
| `provider` | VARCHAR(30) | `instagram`, `facebook` ou `whatsapp`. |
| `event_type` | VARCHAR(60) | `social.message.received`, `social.comment.received`. |
| `external_event_id` | VARCHAR(120) | Identificador original na Meta (`mid`, `comment_id`). |
| `payload_json` | JSON | Payload completo normalizado. |
| `processing_status` | VARCHAR(30) | `pending`, `forwarded`, `processed`, `failed`, `ignored`. |
| `attempt_count` | INT | Tentativas de despacho. |
| `last_error` | TEXT | Registo de erro em caso de falha. |
| `received_at` / `processed_at` | TIMESTAMP / DATETIME | Marcadores temporais. |

#### 3. `social_conversations`
Rastreamento do fio de conversa entre a loja e o cliente externo.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | Identificador interno. |
| `store_id` | INT | ID da loja. |
| `connection_id` | INT | ID da conexao social. |
| `provider` | VARCHAR(30) | Canal de comunicacao. |
| `external_conversation_id` | VARCHAR(120) | ID da conversa na Meta. |
| `external_customer_id` | VARCHAR(120) | PSID (Messenger) ou IGSID (Instagram). |
| `customer_name` | VARCHAR(150) | Nome visivel do cliente externo. |
| `last_message_at` | TIMESTAMP | Marcador da ultima interacao. |
| `conversation_status` | VARCHAR(30) | `open`, `closed`, `pending_human`. |

#### 4. `social_outbound_messages`
Auditoria de todas as mensagens de saida enviadas pelo n8n ou pelo lojista.

| Campo | Tipo | Descricao |
| :--- | :--- | :--- |
| `id` | INT AUTO_INCREMENT (PK) | ID sequencial. |
| `store_id` | INT | ID da loja. |
| `connection_id` | INT | ID da conexao social. |
| `conversation_id` | INT (NULL) | ID da conversa associada. |
| `idempotency_key` | VARCHAR(64) UNIQUE | Chave de idempotencia para prevenir duplicidade. |
| `recipient_external_id` | VARCHAR(120) | PSID ou IGSID do destinatario. |
| `message_text` | TEXT | Texto da mensagem enviada. |
| `status` | VARCHAR(30) | `queued`, `sent`, `failed`, `rejected`. |
| `external_message_id` | VARCHAR(120) | ID retornado pela Meta apos entrega. |
| `error_code` / `error_message` | VARCHAR(60) / TEXT | Detalhes em caso de rejeicao pela Meta. |
| `created_at` / `sent_at` | TIMESTAMP / DATETIME | Marcadores de envio. |

---

### 3.4. Catalogo Completo de Endpoints

#### Verificacao de Saude do Servico

##### `GET /health`
Valida se o gateway de integracoes esta operacional.
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

##### `GET /ready`
Verifica conectividade ativa com a base de dados MySQL.
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

#### Webhooks da Meta

##### `GET /webhooks/meta`
Tratamento do desafio de subscricao (`hub.challenge`) da Meta. Devolve o desafio em texto puro (`200 OK`) caso o `hub.verify_token` coincida.

##### `POST /webhooks/meta`
Recepcao de eventos em tempo real com validacao obrigatoria de assinatura criptografica no cabecalho `X-Hub-Signature-256`.

---

#### Endpoints de Consumo do n8n

##### `GET /n8n/stores/{store_id}/context`
Recupera os dados institucionais da loja para municiar o modelo de IA com regras de atendimento, contactos e localizacao.
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
    "description": "Acessorios eletronicos e gadgets premium.",
    "segment": "Tecnologia",
    "activity": "Comercio a Retalho"
  }
}
```

##### `GET /n8n/stores/{store_id}/products?q={termo}`
Consulta o catalogo da loja filtrando apenas produtos ativos, com precos, fotos e estoque.
```json
{
  "success": true,
  "data": {
    "store_id": 16,
    "total": 1,
    "products": [
      {
        "id": 42,
        "name": "Fone Bluetooth Pro",
        "price": 28500.00,
        "discount_percentage": 0,
        "category": "Audio",
        "stock": 8,
        "in_stock": true,
        "description": "Fone sem fio com cancelamento ativo de ruido.",
        "image": "uploads/products/fone.jpg",
        "is_highlight": true
      }
    ]
  }
}
```

##### `POST /n8n/actions/send-message`
Instrui a Sellex a decifrar o token da pagina e enviar a resposta oficial de volta ao cliente na rede social.
```json
{
  "store_id": 16,
  "connection_id": 4,
  "recipient_external_id": "847291049281749",
  "message_text": "Ola Carlos! O Fone Bluetooth Pro esta disponivel por Kz 28.500,00 com entrega imediata: https://sellex.ao/catalog/?store=kinacode&p=42",
  "idempotency_key": "msg_evt_16_4_847291049281749_step1"
}
```

##### `POST /n8n/events/{event_id}/result`
Regista a conclusao ou falha do workflow para fechar o ciclo de auditoria.
```json
{
  "status": "processed",
  "error": null
}
```

---

### 3.5. Estrutura do Payload Normalizado

A Sellex entrega ao Webhook do n8n payloads padronizados, isolando a complexidade dos diferentes formatos brutos da Meta:

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
    "text": "Ola, tem o fone bluetooth em estoque?"
  },
  "metadata": {
    "source": "meta_webhook",
    "object": "instagram"
  }
}
```

---

### 3.6. Guia Passo a Passo para Fluxos no n8n

```mermaid
sequenceDiagram
    autonumber
    participant Sellex as Sellex Integrations API
    participant n8n as Workflow n8n
    participant LLM as Modelo IA (Claude / GPT)
    participant Cliente as Cliente (Instagram/Messenger)

    Sellex->>n8n: POST no Webhook com payload normalizado
    n8n->>n8n: Responde 200 OK imediato para a Sellex
    n8n->>Sellex: GET /n8n/stores/:id/context (Dados da loja)
    n8n->>Sellex: GET /n8n/stores/:id/products?q=... (Consulta estoque)
    n8n->>LLM: Prompt com Contexto + Catalogo + Pergunta
    LLM-->>n8n: Resposta amigavel com link de checkout
    n8n->>Sellex: POST /n8n/actions/send-message
    Sellex-->>Cliente: Entrega a mensagem no Direct da rede social
    n8n->>Sellex: POST /n8n/events/:id/result (status: processed)
```

1. **Credencial:** Configurar em *Credentials > Header Auth* o cabecalho `Authorization: Bearer <N8N_SERVICE_TOKEN>`.
2. **Webhook Node:** Criar no receptor `POST` no caminho configurado em `N8N_WEBHOOK_URL`.
3. **Confirmacao Rapida:** Devolver `200 OK` imediatamente para liberar a chamada assincrona.
4. **Contexto & Produtos:** Executar nos `HTTP Request` para obter contexto e catalogo ativo.
5. **Agente IA:** Injetar as regras da loja, os produtos filtrados e a duvida do cliente.
6. **Despacho:** Executar no `POST /n8n/actions/send-message` fornecendo a chave de idempotencia.
7. **Fecho:** Marcar o evento como `processed`.

---

### 3.7. Testes e Validacao em Tempo Real

Testes diretos de conectividade podem ser realizados via terminal:

```bash
# Testar contexto da loja 16
curl -X GET "https://api.sellex.ao/integrations/v1/n8n/stores/16/context" \
     -H "Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c"

# Testar busca de produtos
curl -X GET "https://api.sellex.ao/integrations/v1/n8n/stores/16/products?q=fone" \
     -H "Authorization: Bearer sellex_n8n_live_sec_7a9f82bc19405d6e2e8c"
```

---

## 4. Sistema do Funil de Vendas de Redes Sociais

### 4.1. Filosofia: De Interacao a Faturacao Autonoma 24h

O Funil Social da Sellex transforma mensagens e comentarios soltos em vendas concretas na vitrine digital, permitindo ao vendedor faturar mesmo fora do horario comercial.

```mermaid
flowchart LR
    A["1. Interacao Social"] --> B["2. Resposta em <10s com Link de Compra"]
    B --> C["3. Lead Etiquetado no CRM"]
    C --> D["4. Checkout Direto Sellex"]
    D --> E["5. Venda Confirmada 24/7"]
```

---

### 4.2. Ingestao e Deteccao de Eventos Sociais

O sistema escuta e processa 4 pontos de contato da loja:
- **Comentarios em Publicacoes e Reels:** Deteccao de intencao de compra em perguntas sobre preco, disponibilidade e frete.
- **Mensagens Privadas (Direct e Messenger):** Pedidos diretos de atendimento e cotacao.
- **Respostas a Stories:** Interacoes dinamicas em produtos divulgados temporariamente.
- **Mencoes publicas:** Compartilhamentos de clientes e marcacoes do perfil.

---

### 4.3. Motor de Resposta Automatica e Checkout

O motor divide a acao em duas etapas complementares:

1. **Acao Publica Imediata (Comentarios):**
   - Responde em menos de 10 segundos no post para demonstrar atividade da loja.
   - Mensagem padrao: *"Ola [Nome], enviamos-lhe os detalhes completos e disponibilidade no seu privado!"*
2. **Acao Privada de Conversao (Direct / Messenger):**
   - Consulta o produto exato no catalogo Sellex.
   - Envia foto, preco e o link direto para checkout simplificado: *"Ola [Nome]! O produto esta disponivel por [Preco] Kz. Pode finalizar o pedido com entrega segura diretamente por aqui: [Link Sellex]."*
3. **Venda Concluida Autonomamente:**
   - O cliente escolhe cor/tamanho, introduz o endereco e forma de pagamento.
   - O lojista recebe a notificacao de novo pedido confirmado no painel e no e-mail.

---

### 4.4. Organizacao e Etiquetagem de Leads

Cada comprador potencial recebe uma etiqueta automatica baseada na sua maturidade de compra:

| Etiqueta do Lead | Criterio de Enquadramento | Acao Recomendada |
| :--- | :--- | :--- |
| **Lead Frio** | Comentario generico ou mencao superficial. | Apresentacao da vitrine geral. |
| **Lead Interessado** | Pergunta sobre preco, tamanho ou cores de produto especifico. | Envio de ficha tecnica e link direto. |
| **Lead Quente** | Clicou no link da vitrine ou questionou prazos de entrega. | Cupom de desconto ou contato direto. |
| **Cliente Convertido** | Concluiu pelo menos uma compra validada na Sellex. | Agradecimento e retencao de pos-venda. |

---

### 4.5. Metrificacao, Pontuacao e Ranking de Proximidade

Inspirado na interface do Gaio Social, o CRM da Sellex atribui pontos a cada acao para destacar quem esta mais proximo de comprar:

| Acao do Lead | Pontos | Grau de Relevancia |
| :--- | :--- | :--- |
| Comentario num post ou reel | +5 | Descoberta inicial |
| Resposta a story | +10 | Engajamento medio |
| Envio de mensagem privada | +15 | Alta intencao de compra |
| Clique no link do catalogo Sellex | +25 | Pre-checkout |
| Pedido finalizado na plataforma | +50 | Conversao confirmada |

#### Ranking de Oportunidades
No painel do lojista, a aba de **Ranking** lista ordenadamente os contatos com maior pontuacao acumulada nos ultimos 30 dias, direcionando o foco do vendedor para quem ja demonstrou propensao real de compra.

---

### 4.6. Central Hibrida de Atendimento: Live Chat & Inbox

A operacao equilibra automacao e atendimento humano personalizado:

1. **Atendimento Automatico:** Responde a 80% das perguntas rotineiras de preco, catalogo, dados bancarios e localizacao.
2. **Transferencia para Humano (Handover):**
   - Se o cliente digitar palavras como *"falar com atendente"*, *"reclamacao"* ou pedir negociacao especial de quantidade, a automacao suspende o envio automatico para essa conversa.
   - A conversa passa para o topo do **Live Chat** do dashboard com o distintivo *"Aguardando Atendente"*, permitindo ao vendedor assumir a negociacao no mesmo painel.

---

## 5. Guia de Implantacao e Seguranca

### Isolamento Multi-Tenancy Estrito
- Toda operacao de leitura e escrita e estritamente filtrada por `store_id`.
- Nenhuma notificacao in-app contem codigos OTP ou dados de outros lojistas ou sessoes.
- Tokens de acesso a paginas da Meta sao cifrados em repouso com chave de 256 bits (`APP_ENCRYPTION_KEY`).

### Lista de Ficheiros Chave do Projeto

- [seller/index.html](file:///c:/xampp/htdocs/app-sellex/seller/index.html): Interface completa do painel do vendedor (Catalogo, Pedidos, CRM, Funil e Notificacoes).
- [dashboard-seller.js](file:///c:/xampp/htdocs/app-sellex/assets/scripts/dashboard-seller.js): Controlador JavaScript do painel do vendedor, CRM e central de notificacoes.
- [NotificationController.php](file:///c:/xampp/htdocs/app-sellex/api/api-app/controllers/NotificationController.php): Gestao isolada de notificacoes da loja sem vazamento de OTPs.
- [NotificationService.php](file:///c:/xampp/htdocs/app-sellex/api/api-app/services/NotificationService.php): Despacho de e-mails detalhados de pedidos e alertas de stock baixo.
- [OrderController.php](file:///c:/xampp/htdocs/app-sellex/api/api-app/controllers/OrderController.php): Processamento de encomendas, deducao de stock e alertas.
- [styles/seller.css](file:///c:/xampp/htdocs/app-sellex/styles/seller.css): Estilos do painel do lojista, componentes do funil e suporte a modo claro/escuro.
