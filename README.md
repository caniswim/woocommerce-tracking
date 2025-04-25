# WooCommerce Tracking Enhanced

Plugin de rastreamento avançado para WooCommerce com interface personalizada e integração com múltiplas transportadoras, incluindo Correios.

## Funcionalidades

- **Página de rastreamento personalizada**: Interface amigável e responsiva para acompanhamento de pedidos
- **Suporte a múltiplas transportadoras**: Compatível com Correios, Cainiao, AliExpress e outras transportadoras
- **Rastreamento automático**: Detecta códigos de rastreio nas notas de pedidos e metadados
- **Múltiplos métodos de rastreamento**: Consulta por número do pedido, e-mail ou código de rastreio
- **Mensagens adaptativas**: Gera informações fictícias para pedidos sem rastreio baseado no status
- **API REST completa**: Endpoints para integração com sistemas externos
- **Integração com 17Track**: Utiliza a API do 17Track para consulta de múltiplas transportadoras
- **Armazenamento em cache**: Armazena resultados no Firebase para melhor performance
- **Dashboard administrativo**: Gerenciamento de configurações e logs no painel do WordPress

## Shortcode

```
[wcte_tracking_page]
```

Adicione este shortcode a qualquer página para exibir o formulário de rastreamento.

## API REST

### Autenticação

A API pode ser configurada para exigir autenticação via API Key. Para usar:

1. Ative a autenticação no painel administrativo (WooCommerce → Configurações → Rastreamento)
2. Copie sua API Key gerada
3. Inclua no cabeçalho das requisições:
   ```
   X-WCTE-API-Key: sua_api_key
   ```

### Endpoints Disponíveis

#### Endpoint Unificado (recomendado)

```
GET /wp-json/wcte/v1/track?tracking_input=VALOR
```

Processa automaticamente diferentes tipos de entrada (email, número de pedido ou código de rastreio).

**Parâmetros:**
- `tracking_input`: Email, número de pedido ou código de rastreio

**Respostas:**
- Em caso de email: Lista de pedidos associados
- Em caso de número de pedido: Detalhes do pedido com rastreamento
- Em caso de código de rastreio: Informações de rastreamento

#### Listar Pedidos

```
GET /wp-json/wcte/v1/orders
```

**Parâmetros opcionais:**
- `per_page`: Quantidade de pedidos por página (padrão: 10)
- `page`: Número da página
- `status`: Filtrar por status do pedido

#### Detalhes de um Pedido

```
GET /wp-json/wcte/v1/orders/{id}
```

**Parâmetros:**
- `id`: ID do pedido

#### Informações de Rastreamento por Código

```
GET /wp-json/wcte/v1/tracking/{code}
```

**Parâmetros:**
- `code`: Código de rastreio

#### Pedidos por Email

```
GET /wp-json/wcte/v1/tracking/email/{email}
```

**Parâmetros:**
- `email`: Email do cliente

### Formato de Resposta

Todas as respostas seguem o formato:

```json
{
  "success": true,
  "data": { ... }
}
```

Em caso de erro:

```json
{
  "success": false,
  "data": "Mensagem de erro"
}
```

## Exemplos de Uso

### Consultar por Email

```
GET /wp-json/wcte/v1/track?tracking_input=cliente@exemplo.com
```

### Consultar por Número do Pedido

```
GET /wp-json/wcte/v1/track?tracking_input=1234
```

### Consultar por Código de Rastreio

```
GET /wp-json/wcte/v1/track?tracking_input=LB123456789CN
```

## Instalação

1. Faça upload dos arquivos do plugin para o diretório `/wp-content/plugins/woocommerce-tracking-enhanced/`
2. Ative o plugin através do menu 'Plugins' no WordPress
3. Configure as opções em WooCommerce → Configurações → Rastreamento
4. Adicione o shortcode `[wcte_tracking_page]` a uma página para exibir o formulário de rastreamento

## Requisitos

- WordPress 5.6 ou superior
- WooCommerce 4.0 ou superior
- PHP 7.2 ou superior

## Suporte

Para suporte, entre em contato através do [site oficial](https://exemplo.com.br/suporte) ou abra uma issue no repositório do GitHub.
