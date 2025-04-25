# Plano de Desenvolvimento - Integração WooCommerce/Shopify com 17track

## Visão Geral do Projeto
Este documento apresenta o plano de desenvolvimento para implementar um sistema integrado de rastreamento de pedidos que unifica pedidos do WooCommerce (plataforma antiga) e Shopify (plataforma atual), utilizando a API do 17track para rastreamento de encomendas.

## Arquitetura do Sistema
![Arquitetura do Sistema](https://example.com/arquitetura.png)

### Componentes Principais:
1. **Plugin WooCommerce Modificado**: Adaptação do plugin existente para expor uma API REST
2. **Servidor Backend Dedicado**: Aplicação intermediária que coordena as comunicações entre sistemas
3. **App Shopify**: Interface de usuário integrada ao Shopify
4. **Firebase Realtime Database**: Armazenamento centralizado de dados de rastreamento
5. **API 17track**: Serviço externo para consulta de status de rastreamento

## Etapas de Desenvolvimento

### Fase 1: Adaptação do Plugin WooCommerce (4 semanas)

#### Semana 1: Análise e Planejamento
- [x] Auditoria completa do código atual do plugin WooCommerce
- [x] Identificação dos pontos de integração
- [x] Definição da estrutura da API REST
- [x] Documentação dos requisitos de segurança
- [x] Análise da integração atual com Correios e Firebase

#### Semana 2: Implementação da API REST
- [x] Adaptação do sistema atual para expor os dados via endpoints REST
- [x] Implementação de endpoints REST para consulta de pedidos:
  - `GET /wp-json/wcte/v1/orders` - Lista pedidos com filtros
  - `GET /wp-json/wcte/v1/orders/{id}` - Detalhes de um pedido específico 
  - `GET /wp-json/wcte/v1/tracking/{code}` - Informações de rastreamento (adaptar método `get_tracking_info_by_code`) 
  - `GET /wp-json/wcte/v1/tracking/email/{email}` - Pedidos por email (adaptar método existente)
- [x] Implementação do sistema de autenticação via API Keys
- [x] Desenvolvimento da camada de validação de requisições
- [x] Configuração de rate limiting e proteção contra abusos

#### Semana 3: Migração para 17track
- [x] Remoção da integração com API dos Correios (`class-wcte-api-handler.php`)
- [x] Implementação da integração com API do 17track (preservando a lógica de mensagens fictícias)
- [x] Adaptação do sistema de mensagens fictícias para compatibilidade com 17track
- [x] Manutenção da estrutura existente de dados no Firebase
- [x] Implementação de sistema de cache para requisições
- [x] Adaptação do método `get_tracking_info` e `get_tracking_info_by_code` para usar 17track

#### Semana 4: Testes e Otimização
- [ ] Testes de carga nos novos endpoints
- [ ] Testes de compatibilidade com a implementação atual de Firebase
- [ ] Otimização de consultas ao banco de dados e chamadas à API
- [x] Documentação completa da API para desenvolvedores externos
- [ ] Criação de ambiente de homologação

### Fase 2: Desenvolvimento do Servidor Backend (6 semanas)

#### Semana 1-2: Infraestrutura Base
- [ ] Configuração do ambiente de servidor (Node.js/Express)
- [ ] Implementação do sistema de autenticação 
- [ ] Conexão com o Firebase Realtime Database existente (`https://rastreios-blazee-default-rtdb.firebaseio.com`)
- [ ] Preservação da estrutura atual de dados conforme implementado em `class-wcte-database.php`
- [ ] Desenvolvimento da camada de cache
- [ ] Adaptação da integração atual com Slack para o novo servidor

#### Semana 3-4: Integração com WooCommerce e Shopify
- [ ] Desenvolvimento de conectores para a nova API REST do WooCommerce
- [ ] Implementação da integração com a API do Shopify
- [ ] Criação de rotinas de sincronização de dados
- [ ] Desenvolvimento da lógica de unificação de pedidos
- [x] Implementação de sistema para detectar códigos de rastreio usando expressões regulares (similar ao existente em `get_tracking_codes_from_order`)

#### Semana 5-6: Integração com 17track e Otimização
- [x] Implementação da integração direta com API do 17track
- [x] Adaptação do sistema de mensagens fictícias (`get_fictitious_message`) para o servidor central
- [x] Desenvolvimento de sistema de filas para processamento assíncrono
- [ ] Configuração de métricas e monitoramento
- [ ] Testes de carga e otimizações finais
- [x] Implementação de sistema para lidar com diferentes formatos de código de rastreio (similar ao `is_cainiao_tracking`)

### Fase 3: Desenvolvimento do App Shopify (4 semanas)

#### Semana 1: Configuração e Estrutura
- [ ] Configuração do ambiente de desenvolvimento Shopify
- [ ] Criação da estrutura base do aplicativo
- [ ] Implementação do sistema de autenticação OAuth
- [ ] Configuração da integração com o servidor backend

#### Semana 2-3: Interface e Funcionalidades
- [ ] Desenvolvimento da interface de rastreamento similar à página atual (`class-wcte-tracking-page.php`)
- [ ] Implementação da visualização de pedidos unificados
- [ ] Recriação da lógica de exibição de mensagens fictícias e reais combinadas
- [ ] Implementação da funcionalidade de busca por email, código de rastreio ou número de pedido
- [ ] Criação de filtros e opções de pesquisa
- [ ] Desenvolvimento de sistema de notificações

#### Semana 4: Testes e Publicação
- [ ] Testes de integração completos
- [ ] Otimização de performance
- [ ] Preparação da documentação para lojistas
- [ ] Submissão para a Shopify App Store

### Fase 4: Implantação e Monitoramento (2 semanas)

#### Semana 1: Implantação
- [ ] Migração dos dados históricos do Firebase atual
- [ ] Configuração de ambientes de produção
- [ ] Implantação em paralelo com sistema existente
- [ ] Testes finais de integração
- [ ] Verificação da compatibilidade com mensagens fictícias existentes

#### Semana 2: Estabilização
- [ ] Monitoramento intensivo do sistema
- [ ] Ajustes de performance baseados em uso real
- [ ] Documentação final do sistema
- [ ] Treinamento para equipe de suporte

## Detalhes Técnicos

### Estrutura de API do Plugin WooCommerce

#### Endpoints:
- `GET /wp-json/wcte/v1/orders` - Lista pedidos com filtros
- `GET /wp-json/wcte/v1/orders/{id}` - Detalhes de um pedido específico
- `GET /wp-json/wcte/v1/tracking/{code}` - Informações de rastreamento
- `GET /wp-json/wcte/v1/tracking/email/{email}` - Pedidos por email

#### Autenticação:
Será implementada autenticação via API Keys com HMAC para assinatura de requisições, garantindo segurança sem comprometer a performance.

### Servidor Backend

#### Tecnologias:
- **Linguagem**: Node.js com TypeScript
- **Framework**: Express.js
- **Banco de Dados**: Firebase Realtime Database (manter a estrutura existente)
- **Cache**: Redis
- **Filas**: Bull
- **Monitoramento**: Prometheus + Grafana
- **Integração de Notificações**: Slack (preservando a funcionalidade atual)

#### Serviços:
- API Gateway
- Serviço de Rastreamento (migração da lógica atual para 17track)
- Serviço de Sincronização
- Gerenciador de Cache
- Sistema de Mensagens Fictícias (adaptado do sistema atual)
- Processador de Filas
- Conectores para WooCommerce e Shopify

### App Shopify

#### Tecnologias:
- **Frontend**: React.js
- **Framework Shopify**: Polaris
- **Autenticação**: OAuth 2.0
- **Estado**: Redux
- **API Client**: GraphQL e REST

#### Funcionalidades:
- Página de rastreamento personalizada (similar à atual no WooCommerce)
- Embeddable App para painel administrativo
- Webhooks para sincronização em tempo real
- Notificações para clientes
- Suporte a mensagens fictícias para pedidos sem rastreio
- Interface para gerenciar mensagens fictícias (similar à atual)

## Requisitos de Infraestrutura

### Servidor Backend:
- Servidor VPS com mínimo 4GB RAM, 2 vCPUs
- Ubuntu 20.04 LTS
- Nginx como proxy reverso
- Certificados SSL Let's Encrypt
- Backup automático diário

### Banco de Dados:
- Firebase Realtime Database com plano Blaze
- Backup diário dos dados críticos

### Monitoramento:
- Alerta para latência acima de 500ms
- Monitoramento de uso de API do 17track
- Alertas de erro via Slack

## Considerações de Segurança

### Proteção de Dados:
- Todas as comunicações via HTTPS
- Tokens com expiração curta
- Dados sensíveis criptografados em repouso
- Validação rigorosa de todas as entradas

### Auditoria:
- Log de todas as requisições à API
- Registro de ações administrativas
- Monitoramento de tentativas de acesso não autorizado

## Cronograma de Entrega
- **Fase 1** (Adaptação WooCommerce): 4 semanas
- **Fase 2** (Servidor Backend): 6 semanas
- **Fase 3** (App Shopify): 4 semanas
- **Fase 4** (Implantação): 2 semanas

**Tempo Total Estimado**: 16 semanas (4 meses)

## Equipe Necessária
- 1 Desenvolvedor Backend Senior (WooCommerce/PHP)
- 1 Desenvolvedor Backend Senior (Node.js/Firebase)
- 1 Desenvolvedor Frontend/Shopify
- 1 DevOps
- 1 Gerente de Projeto

## Estratégia de Migração
A migração será realizada em fases, mantendo o sistema atual operacional:

1. Implantação da API REST no WooCommerce sem interromper o funcionamento atual do plugin
2. Validação do funcionamento da API em paralelo com o sistema atual
3. Implantação do servidor backend mantendo compatibilidade com a estrutura de dados do Firebase
4. Período de operação conjunta para validação
5. Migração gradual dos clientes para o novo sistema, preservando os dados históricos

## Progresso Atual

### Fase 1 (Adaptação do Plugin WooCommerce)
- Implementada a API REST com todos os endpoints previstos
- Desenvolvido sistema de autenticação por API Keys com controle de rate limiting
- Migração da integração dos Correios para 17track concluída
- Sistema de mensagens fictícias adaptado para funcionar com o 17track
- Implementado sistema de cache para reduzir chamadas à API do 17track
- Implementado sistema de processamento em lote via cron para atualização de rastreamentos
- Adicionada página de configuração da API REST
- Adicionada página de configuração da integração com 17track

## Próximos Passos
1. Concluir testes de carga e otimizações
2. Criar ambiente de homologação
3. Iniciar desenvolvimento do servidor backend (Fase 2) 