# Addon Asaas NF — Emissão de NFS-e pelo WHMCS

Guia de instalação, configuração e operação do addon `asaas_nf`, que agenda notas fiscais de serviço (NFS-e) no Asaas a partir das faturas do WHMCS.

## Como funciona

1. O addon lê a fatura do WHMCS, o cliente e os itens.
2. Localiza (ou cadastra) o cliente no Asaas pelo CPF/CNPJ.
3. Verifica no Asaas se já existe nota para a fatura (`externalReference = WHMCS-{id da fatura}`). Se existir, **não cria outra**.
4. Procura a cobrança do gateway para a fatura (`payments?externalReference={id da fatura}`):
   - **com cobrança:** a nota é vinculada a ela (`payment`). No Asaas, a nota aparece na cobrança, com o link de pagamento;
   - **sem cobrança** (fatura ainda não aberta pelo cliente ou paga por outro meio): a nota é emitida avulsa para o cliente (`customer`).
5. Envia `POST /v3/invoices` com:
   - `serviceDescription`: número da fatura + descrição dos itens (produto, domínio, período);
   - serviço municipal **fixo** (`municipalServiceId` ou `municipalServiceCode` + `municipalServiceName`);
   - objeto `taxes` com as alíquotas e os campos da Reforma Tributária.
6. O Asaas agenda a nota; a autorização da prefeitura chega depois pelo webhook e fica registrada nas observações da fatura.

> O nome do serviço municipal nunca recebe dados variáveis da fatura. Assim o Asaas não cadastra um serviço novo a cada emissão.

## Instalação

1. Envie os arquivos para o WHMCS:
   - `modules/addons/asaas_nf/asaas_nf.php`
   - `modules/addons/asaas_nf/hooks.php` (necessário para a emissão automática)
   - `modules/addons/asaas_nf/lib/functions.php` (lógica de emissão, usada pelos dois arquivos acima)
   - `modules/gateways/callback/asaas.php` (recebe os status da nota)
2. Em **Configurações → Módulos Addon**, ative **Asaas NF** e libere o acesso para os grupos de administradores desejados.
3. Preencha a configuração (próxima seção) e salve.

## Pré-requisitos no Asaas

- Informações fiscais da conta configuradas (**Notas fiscais → Configurações**). O addon consulta `GET /v3/fiscalInfo` e bloqueia a emissão se não houver configuração.
- O serviço que será usado nas notas já cadastrado, ou o código do serviço em mãos:
  - **Prefeituras com lista de serviços:** consulte `GET /v3/fiscalInfo/services` e use o `id` retornado.
  - **Portal Nacional:** não existe lista; use o código de tributação nacional no formato com pontos (ex.: `01.03.02`).
- Webhook configurado com os eventos de nota fiscal (próxima seção).

## Webhook

Os status da nota chegam pelo mesmo callback do gateway Asaas:

```text
https://SEU_DOMINIO/modules/gateways/callback/asaas.php
```

- O gateway Asaas precisa estar ativo no WHMCS: o callback valida o header `asaas-access-token` com o **Token do Webhook** configurado no gateway. Um token diferente faz o evento ser recusado com 403 (registrado no log do gateway).
- No painel do Asaas (**Configurações → Integrações → Webhooks**), ative os eventos de nota fiscal no webhook de cobranças já existente ou crie outro webhook com a mesma URL e o mesmo token.

| Evento | Registro nas observações da fatura |
|---|---|
| `INVOICE_CREATED` | Nota agendada |
| `INVOICE_SYNCHRONIZED` | Nota sincronizada com a prefeitura |
| `INVOICE_AUTHORIZED` | Nota autorizada e emitida, com o número da nota |
| `INVOICE_ERROR` | Nota com erro, com o motivo informado pela prefeitura |
| `INVOICE_CANCELED` | Nota cancelada |
| `INVOICE_CANCELLATION_DENIED` | Cancelamento negado pela prefeitura |

A fatura é localizada pelo `externalReference` da nota (`WHMCS-<id da fatura>`) ou, na falta dele, pelo id da nota gravado nas observações na emissão. Eventos repetidos não duplicam a linha de status.

Se o webhook falhar (token divergente, fila pausada no Asaas), use **Sincronizar status das NFs** no painel do addon: ele consulta no Asaas as notas agendadas e sincronizadas e atualiza as observações.

Os eventos de pagamento, assinatura e Pix Automático do gateway estão listados no [README](../README.md#configuração-do-webhook).

## Configuração do addon

### Acesso e modo de emissão

| Campo | Descrição |
|---|---|
| Chave de API | API Key da conta Asaas. |
| Modo Sandbox | Usa `sandbox.asaas.com` em vez da produção. |
| Modo de emissão | `Manual` (somente pelo painel) ou `Automático`. |
| Gatilho automático | Quando emitir no modo automático: na geração da fatura, no dia do vencimento (cron diário) ou no pagamento. A cobrança no Asaas só é criada quando o cliente abre a fatura; por isso, o gatilho "Na geração da cobrança" costuma gerar nota avulsa. Para a nota sair vinculada à cobrança, prefira "Quando houver pagamento". |
| Endpoint de emissão | Mantenha `/invoices`. |

### Serviço municipal

| Campo | Descrição |
|---|---|
| Serviço usado na NF | **Sempre o serviço padrão:** todas as notas usam o mesmo serviço. **Um serviço por produto do WHMCS:** o nome do produto vira o nome do serviço (um cadastro por produto, reaproveitado nas notas seguintes). |
| ID do serviço municipal | Opcional. `id` de `GET /v3/fiscalInfo/services`. Quando preenchido, é usado no modo padrão e o código não é enviado. |
| Nome do serviço padrão | Nome **exato** do serviço já cadastrado no Asaas. O addon procura esse nome antes de enviar o código, para não duplicar. |
| Código do serviço municipal | Enviado como `municipalServiceCode` quando não há ID. Vazio = usa o código de tributação nacional. |
| Código de tributação nacional | Ex.: `01.03.02` (hospedagem de dados), no formato com pontos. Usado no Portal Nacional. |
| Descrição padrão | Usada na descrição da nota quando a fatura não tem itens com descrição. |
| Município padrão da prestação | Mantido por compatibilidade; não é enviado na API atual de notas. |

Ordem de escolha do serviço em cada emissão:

1. ID configurado (modo padrão);
2. serviço já existente no Asaas com o mesmo nome;
3. código do serviço + nome fixo.

### Tributos e Reforma Tributária (IBS/CBS)

| Campo | Enviado como | Exemplo |
|---|---|---|
| Código NBS | `taxes.nbsCode` | `1.1506.21.00` |
| Situação tributária IBS/CBS (CST) | `taxes.taxSituationCode` | `000` |
| Classificação tributária (cClassTrib) | `taxes.taxClassificationCode` | `000001` |
| Indicador da operação | `taxes.operationIndicatorCode` | `010103` |
| Tributos padrão | `taxes.iss`, `pis`, `cofins`, `csll`, `inss`, `ir`, `retainIss`, `operationPis`, `operationCofins`, `pisCofinsTaxStatus` | `{"iss":5,"pis":0,"cofins":0,"csll":0,"inss":0,"ir":0,"retainIss":false,"pisCofinsTaxStatus":"STANDARD_TAXABLE_OPERATION","operationPis":0.65,"operationCofins":3}` |

- Campos da Reforma deixados em branco **não são enviados**.
- Em `taxes`, `pis`, `cofins`, `csll`, `inss` e `ir` são alíquotas **retidas pelo tomador**. O PIS/COFINS próprio da empresa vai em `operationPis` e `operationCofins`. Usar `pis`/`cofins` para isso faz a nota sair com retenção indevida.
- O cClassTrib precisa ser compatível com o CST informado.
- Prazos do Asaas: serviços em geral a partir de **01/10/2026**; cenários específicos a partir de 01/12/2026; IBS/CBS para optantes do Simples Nacional a partir de **01/01/2027**.
- Os códigos acima são exemplos. Confirme os valores corretos com a sua contabilidade.

## Dados do cliente

O cliente do WHMCS precisa ter:

- nome ou razão social (campo personalizado com "razão", "empresa" ou "social", ou o campo Empresa);
- CPF/CNPJ: o addon usa o mesmo campo configurado no gateway Asaas ("Campo de CPF"). Se o gateway não estiver configurado, procura um campo personalizado cujo nome contenha "cpf", "cnpj" ou "document";
- e-mail.

Ao cadastrar o cliente no Asaas, o gateway e o addon enviam os dados do WHMCS:

| WHMCS | Asaas |
|---|---|
| Endereço 1 (`Rua X, 123 - Sala 4`) | `address`, `addressNumber` e `complement`. Sem número, envia `S/N`. |
| Endereço 2 | `province` (bairro) |
| CEP | `postalCode` (o Asaas deduz cidade e estado pelo CEP) |
| Telefone (`+55.31999998888`) | `mobilePhone` para celular com 11 dígitos; `phone` para fixo com 10 dígitos |
| ID do cliente | `externalReference = WHMCS-CLIENT-{id}` |

Sem cadastro duplicado:

- o cliente é procurado pelo CPF/CNPJ, e um cadastro novo só é criado quando a busca responde com sucesso e sem resultados. Se a consulta falhar, a operação é interrompida em vez de criar outro cliente;
- uma trava por CPF/CNPJ impede que dois processos criem o mesmo cliente ao mesmo tempo;
- em um cliente já existente, só são preenchidos os dados que faltam (telefone, endereço, bairro, CEP). Os dados já cadastrados no Asaas não são sobrescritos.

## Operação pelo painel

Menu **Addons → Asaas NF**:

- **Aplicar filtro:** apenas filtra a lista (status da fatura, pendentes/emitidas, quantidade). Não emite nada.
- **Emitir NF agora:** emite a nota de uma fatura, com confirmação.
- **Emitir NF selecionadas:** emite as faturas marcadas na lista.
- **Emitir NF em lote (filtro):** emite todas as faturas do filtro atual, até o limite escolhido, após confirmação. Faturas canceladas, rascunho, reembolsadas ou em cobrança são ignoradas.
- **Resetar NF:** limpa o registro da nota **somente no WHMCS**. A nota no Asaas não é cancelada, e o addon continua bloqueando nova emissão enquanto houver nota ativa no Asaas para a fatura.

Status exibidos na coluna NF: Pendente, NF agendada, NF sincronizada, NF emitida, Erro na NF.

## Proteções contra duplicidade

- Registro `[Asaas NF]` nas observações da fatura.
- Consulta ao Asaas por `externalReference` antes de cada envio (notas canceladas ou com erro não bloqueiam).
- Consulta às notas das cobranças da fatura: se o próprio Asaas já emitiu nota para a cobrança gerada pelo gateway (emissão automática configurada no painel do Asaas), o addon não emite outra.
- Se alguma dessas consultas falhar, a emissão é bloqueada.
- Trava por fatura no banco (`GET_LOCK`), impedindo emissões simultâneas da mesma fatura (ex.: gancho automático e clique manual).
- Nome de serviço fixo: nenhum serviço novo é criado por fatura.

## Diagnóstico

- **Utilitários → Logs → Log de Módulos:** todas as chamadas à API (módulo `asaas_nf`) com requisição e resposta. A chave de API é mascarada.
- **Log de Atividades:** emissões enviadas e falhas da emissão automática.
- **Observações da fatura:** histórico de status recebido pelo webhook, incluindo o motivo de erro da prefeitura.

### Erros comuns

| Mensagem | Causa provável |
|---|---|
| Configuração fiscal incompleta no módulo Asaas NF | Falta API Key, nome do serviço ou ID/código do serviço, ou o JSON de tributos é inválido. |
| Configure as informações fiscais da conta no Asaas | A conta Asaas não tem as informações fiscais cadastradas. |
| Serviço "..." não encontrado no Asaas e nenhum código de serviço foi configurado | Preencha o ID do serviço ou um código de serviço. |
| O parâmetro Descrição do Serviço não pode ser vazio | O serviço foi enviado sem ID nem código válido. Revise a seção "Serviço municipal" e confira a requisição no Log de Módulos. |
| Já existe a NF ... no Asaas para esta fatura | Proteção de duplicidade. Nada foi criado. |

## Referências

- [Agendar nota fiscal (POST /v3/invoices)](https://docs.asaas.com/reference/agendar-nota-fiscal)
- [Emitindo notas fiscais de serviço](https://docs.asaas.com/docs/emitindo-notas-fiscais-de-servico)
- [Adequando sua integração à Reforma Tributária](https://docs.asaas.com/docs/adequando-sua-integra%C3%A7%C3%A3o-%C3%A0-reforma-tribut%C3%A1ria)
- [Listar serviços municipais](https://docs.asaas.com/reference/listar-servicos-municipais)
