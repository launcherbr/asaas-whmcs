# Asaas WHMCS Gateway

<div align="center">
  <table>
    <tr>
      <td align="center" bgcolor="#1E90FF" style="padding: 18px 28px; border-radius: 12px; color: #ffffff; font-family: Arial, sans-serif;">
        <strong style="font-size: 24px;">🚀 Convite Asaas</strong><br>
        <span style="font-size: 16px;">Ao se cadastrar pelo link abaixo, o indicado recebe <strong>R$ 50,00</strong> em crédito promocional para testar o Asaas.</span><br><br>
        <a href="https://www.asaas.com/r/349d3b35-51c0-4b6c-a262-f351afd6ef3d" style="color: #ffffff; text-decoration: underline; font-weight: bold;">https://www.asaas.com/r/349d3b35-51c0-4b6c-a262-f351afd6ef3d</a>
      </td>
    </tr>
  </table>
</div>

Produto completo de cobrança e gestão financeira para WHMCS, com integração nativa ao Asaas e arquitetura pronta para operação real em ambientes de cobrança recorrente, Pix, faturamento e emissão fiscal.

A solução foi concebida como uma entrega completa para operação profissional em WHMCS, com estrutura pronta para uso em cenários reais de cobrança, assinatura e emissão de notas fiscais.

## Produto

O módulo combina três componentes essenciais para operação em WHMCS:

- gateway de pagamento para cobrança avulsa e recorrente
- callback de sincronização com eventos do Asaas
- addon de emissão de notas fiscais com operação manual ou automatizada

## Visão geral da solução

O produto foi pensado para atender ao ciclo completo de operação de um ambiente WHMCS com cobrança via Asaas:

- cobrança individual por fatura
- recorrência / assinatura
- Pix automático
- confirmação de pagamento via webhook
- sincronização de status de fatura e serviço
- emissão fiscal de acordo com a configuração escolhida pelo cliente

## Funcionalidades principais

### Gateway WHMCS
- integração com API v3 do Asaas
- suporte a cobrança avulsa
- suporte a assinatura recorrente
- mapeamento do ciclo de cobrança do WHMCS
- opções de configuração para operação clara e profissional
- gerenciamento de clientes e cobrança com sincronização automática

### Pix Automático
- débito recorrente via Pix com autorização prévia do cliente (Jornada 3 da API do Asaas)
- a primeira fatura recorrente é paga por um QR Code que também autoriza os débitos seguintes
- as próximas faturas viram cobranças vinculadas à autorização, criadas de 3 a 10 dias úteis antes do vencimento
- débito recusado pelo banco fica registrado nas observações da fatura
- opção separada de "Pix como forma padrão", que apenas gera cobranças só com Pix, pagas QR Code a QR Code

### Webhook / callback
- autenticação por token
- processamento de eventos do Asaas
- sincronização de pagamentos e assinatura
- atualização do status da fatura e do serviço no WHMCS

### Addon de NF
- fluxo separado do gateway para manter responsabilidade clara
- emissão manual de nota fiscal por fatura
- emissão automática conforme configuração do módulo
- gatilhos em geração da cobrança, vencimento ou pagamento
- visão por pendentes e emitidas
- seleção individual ou em lote, sempre com confirmação
- serviço municipal fixo (serviço padrão ou um por produto do WHMCS), sem criar serviços novos a cada emissão
- campos da Reforma Tributária (IBS/CBS): NBS, CST, cClassTrib e indicador da operação
- proteção contra notas duplicadas por fatura, com consulta ao Asaas antes de cada envio
- registro das chamadas à API no Log de Módulos do WHMCS

Instruções completas do addon: [docs/NOTA-FISCAL.md](docs/NOTA-FISCAL.md).

## Arquitetura do projeto

- `modules/gateways/asaas.php` — implementação do módulo de pagamento
- `modules/gateways/callback/asaas.php` — processamento de eventos do Asaas
- `includes/hooks/asaas_pix_automatico.php` — cron diário que cria as cobranças do Pix Automático
- `modules/addons/asaas_nf/asaas_nf.php` — addon para emissão de NF com controle manual ou automático
- `modules/addons/asaas_nf/hooks.php` — ganchos da emissão automática (geração, vencimento e pagamento)
- `modules/addons/asaas_nf/lib/functions.php` — lógica de emissão compartilhada pelo addon e pelos ganchos
- `docs/NOTA-FISCAL.md` — instruções de configuração e operação do addon de NF

## Requisitos

- WHMCS 8.x
- PHP 7.4 ou superior
- cURL habilitado
- JSON habilitado
- conta ativa no Asaas com API Key válida

## Instalação

1. Faça upload do gateway para a estrutura do WHMCS:
   - `modules/gateways/asaas.php`
   - `modules/gateways/callback/asaas.php`
   - `includes/hooks/asaas_pix_automatico.php` (necessário para o Pix Automático)
2. Faça upload do addon:
   - `modules/addons/asaas_nf/asaas_nf.php`
   - `modules/addons/asaas_nf/hooks.php`
   - `modules/addons/asaas_nf/lib/functions.php`
3. Ative o gateway no painel administrativo do WHMCS.
4. Configure a API Key do Asaas.
5. Defina o ambiente Sandbox conforme necessário.
6. Ative o addon Asaas NF e configure o serviço municipal, os tributos e o modo de emissão, conforme [docs/NOTA-FISCAL.md](docs/NOTA-FISCAL.md).

## Configuração do gateway

No painel do WHMCS, configure:

- Chave de API do Asaas
- modo Sandbox
- opções de assinatura e recorrência
- Pix como forma padrão (cobranças só com Pix)
- Pix Automático (débito recorrente autorizado)
- token do webhook
- status e ciclo de cobrança
- campos personalizados de documento do cliente

## Configuração do webhook

No painel do Asaas, configure o webhook com a URL:

```text
https://SEU_DOMINIO.com.br/modules/gateways/callback/asaas.php
```

Utilize o mesmo token de autenticação informado no WHMCS e ative os eventos abaixo. Pagamentos, assinaturas e notas fiscais usam a mesma URL e o mesmo token; os eventos podem ficar em um único webhook ou em webhooks separados.

**Pagamentos** (baixa automática da fatura e ativação dos serviços pendentes ou suspensos da fatura):

- `PAYMENT_CONFIRMED`
- `PAYMENT_RECEIVED`

**Assinaturas** (suspensão dos serviços da fatura quando a assinatura é encerrada no Asaas):

- `SUBSCRIPTION_INACTIVATED`
- `SUBSCRIPTION_DELETED`
- `SUBSCRIPTION_UPDATED` (suspende quando a assinatura passa a inativa ou expirada)
- `SUBSCRIPTION_CREATED` (apenas registrado no log; o serviço é ativado pelo pagamento)

Os eventos alteram somente os serviços cobrados na fatura vinculada à cobrança ou à assinatura, sem mexer nos demais serviços do cliente.

**Pix Automático** (somente com a opção Pix Automático ativa):

- `PIX_AUTOMATIC_RECURRING_AUTHORIZATION_ACTIVATED` (baixa na primeira fatura, paga pelo QR Code da autorização)
- `PIX_AUTOMATIC_RECURRING_AUTHORIZATION_CREATED`, `PIX_AUTOMATIC_RECURRING_AUTHORIZATION_CANCELLED`, `PIX_AUTOMATIC_RECURRING_AUTHORIZATION_EXPIRED` e `PIX_AUTOMATIC_RECURRING_AUTHORIZATION_REFUSED` (situação da autorização)
- `PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_REFUSED` (registra na fatura o débito recusado)
- `PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_CREATED`, `PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_SCHEDULED` e `PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_CANCELLED` (opcionais, apenas log)

As faturas seguintes do Pix Automático são baixadas pelos eventos de pagamento acima (`PAYMENT_CONFIRMED`/`PAYMENT_RECEIVED`).

**Notas fiscais** (histórico da NFS-e nas observações da fatura):

- `INVOICE_CREATED`
- `INVOICE_SYNCHRONIZED`
- `INVOICE_AUTHORIZED`
- `INVOICE_ERROR`
- `INVOICE_CANCELED`
- `INVOICE_CANCELLATION_DENIED`

Outros eventos enviados pelo Asaas são aceitos e apenas registrados no log do gateway, sem alterar a fatura.

## Fluxo operacional

### Cobrança avulsa
- a fatura é criada no WHMCS
- o gateway gera a cobrança no Asaas
- o cliente paga via Pix, boleto ou cartão
- o webhook atualiza o status da fatura

### Assinatura e recorrência
- o fluxo é tratado pelo gateway de forma específica
- o ciclo de cobrança é mapeado conforme a configuração da assinatura no WHMCS
- os status são sincronizados por webhook

### Pix Automático
- disponível em faturas com serviços de ciclo mensal, trimestral, semestral ou anual (um único ciclo por fatura); as demais seguem o fluxo comum
- tem prioridade sobre o modo Assinatura nessas faturas, para o cliente não ser cobrado duas vezes
- sem autorização ativa, a fatura mostra o QR Code do Pix Automático: o pagamento quita a fatura e autoriza os débitos seguintes
- com autorização ativa, a cobrança vinculada é criada pelo cron diário (ou ao abrir a fatura) entre 3 e 10 dias úteis antes do vencimento, e o banco do cliente debita no vencimento
- fatura aberta com menos de 3 dias úteis para o vencimento, ou já vencida, recebe uma cobrança avulsa comum
- o cliente pode pagar antes pelo botão "Pagar agora"; o débito automático daquela fatura é cancelado
- se o valor da fatura mudar antes do pagamento do QR Code, a autorização pendente é cancelada e um novo QR Code é gerado
- cada cliente mantém uma autorização ativa; ao ativar uma nova, as anteriores são canceladas
- requisitos do Asaas: conta Pessoa Jurídica elegível ao Pix Automático (CNPJ ativo há pelo menos seis meses, sem pendências)
- as autorizações ficam na tabela `mod_asaas_pix_automatico`, criada no primeiro uso

### Emissão de NF
- a nota fiscal pode ser emitida manualmente pelo addon
- ou automaticamente conforme a configuração do módulo
- o gatilho pode ser na geração da cobrança, no vencimento (cron diário) ou no pagamento
- antes de enviar, o addon verifica no Asaas se a fatura já tem nota ativa
- a descrição da nota traz os itens da fatura; o nome do serviço municipal é sempre fixo
- o Asaas agenda a nota e o webhook registra na fatura a autorização ou o erro da prefeitura

## Diferencial do produto

A principal vantagem da solução é a separação correta de responsabilidades:

- gateway: cobrança e pagamentos
- callback: monitoramento e atualização de status
- addon NF: operação fiscal com controle manual ou automático

Essa arquitetura preserva flexibilidade para cenários em que a nota fiscal precisa ser emitida antes do recebimento, no vencimento ou em processamento automático conforme a política da operação.

## Segurança e confiabilidade

- validação de webhook por token
- controle de origem da chamada
- sanitização de dados cadastrais
- observabilidade por logs e histórico de atividade
- chamadas do addon de NF registradas no Log de Módulos, com a chave de API mascarada
- bloqueio de emissão duplicada por fatura
- estrutura preparada para operação com fluxos reais de cobrança e faturamento

## Licença

Este produto foi desenvolvido para uso com WHMCS e Asaas, e deve ser adaptado às regras e estruturas específicas de cada ambiente de instalação.

## Suporte

Valide:

- ambiente de teste e produção
- token do webhook
- campos personalizados de cliente
- fluxo de assinatura
- permissões da API Asaas
- logs de cobrança e evento
- configuração fiscal da conta Asaas e serviço municipal usado nas notas
- Log de Módulos do WHMCS (módulo `asaas_nf`) em caso de erro na emissão

---

> ## ⚠️ Disclaimer Legal e Comercial
>
> Este módulo é de propriedade da <strong>Launcher Tecnologia Ltda ME</strong>, inscrita no CNPJ: <strong>26.651.889/0001-60</strong>, com marca comercial e operação sob a fantasia <strong>Launcher Tech</strong>.
>
> O software é disponibilizado exclusivamente como <strong>módulo comercial com distribuição gratuita</strong>, sendo concedido por cortesia para uso e avaliação, sem qualquer intenção de venda, revenda, comercialização ou distribuição comercial indevida.
>
> É expressamente proibida a venda, revenda, reprodução em massa, redistribuição com fins lucrativos ou uso em contextos que configurem comercialização do código ou de versões derivadas sem autorização prévia e formal da titular dos direitos.
>
> Qualquer uso, adaptação ou redistribuição deve respeitar os termos de propriedade intelectual da empresa e a finalidade de uso cortês e não comercial.
>
> Asaas WHMCS Gateway — solução completa para cobrança, recorrência, sincronização de pagamentos e emissão fiscal operacional.
