<?php
/**
 * Callback Asaas para WHMCS
 * Recebe notificações de pagamento e dá baixa na fatura.
 * Inclui validação obrigatória do Token de Webhook.
 */

// Carregar o sistema do WHMCS
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use WHMCS\Database\Capsule;

function asaas_callback_MetaData()
{
    return array(
        'DisplayName' => 'Asaas Callback',
        'APIVersion' => '2.0',
        'Description' => 'Webhook de sincronização de pagamentos e assinaturas do Asaas para WHMCS.',
        'Author' => 'Launcher Tech',
    );
}

function asaasUpdateServiceStatusByInvoice($invoiceId, $status)
{
    if (empty($invoiceId) || !function_exists('localAPI')) {
        return false;
    }

    // Só os serviços cobrados nesta fatura, para não alterar outros serviços do cliente.
    // Ativa apenas o que está pendente ou suspenso e suspende apenas o que está ativo,
    // sem reativar serviços cancelados ou encerrados.
    $fromStatuses = ($status === 'Suspended') ? ['Active'] : ['Pending', 'Suspended'];

    try {
        $serviceIds = Capsule::table('tblinvoiceitems')
            ->join('tblhosting', 'tblhosting.id', '=', 'tblinvoiceitems.relid')
            ->where('tblinvoiceitems.invoiceid', (int) $invoiceId)
            ->where('tblinvoiceitems.type', 'Hosting')
            ->whereIn('tblhosting.domainstatus', $fromStatuses)
            ->distinct()
            ->pluck('tblhosting.id');
    } catch (\Exception $e) {
        logTransaction('asaas', ['invoiceId' => $invoiceId, 'status' => $status, 'error' => $e->getMessage()], 'Erro Consulta Serviços da Fatura');
        return false;
    }

    foreach ($serviceIds as $serviceId) {
        try {
            localAPI('UpdateClientProduct', [
                'serviceid' => (int) $serviceId,
                'status' => $status,
            ]);
        } catch (\Exception $e) {
            logTransaction('asaas', ['serviceid' => $serviceId, 'status' => $status, 'error' => $e->getMessage()], 'Erro Atualização Serviço');
        }
    }

    return true;
}

function asaasUpdateInvoiceNFStatusByReference($externalReference, $event, $payload)
{
    if (empty($externalReference)) {
        return false;
    }

    $invoiceId = null;
    if (preg_match('/^WHMCS-(\d+)$/i', trim((string) $externalReference), $matches)) {
        $invoiceId = (int) $matches[1];
    }

    // Sem externalReference, localiza a fatura pelo id da NF gravado nas notas na emissão.
    $asaasNfId = isset($payload->invoice->id) ? (string) $payload->invoice->id : '';
    if (empty($invoiceId) && preg_match('/^inv_[A-Za-z0-9]+$/', $asaasNfId)) {
        $invoiceId = (int) Capsule::table('tblinvoices')->where('notes', 'like', '%' . $asaasNfId . '%')->value('id');
    }

    if (empty($invoiceId)) {
        logTransaction('asaas', ['externalReference' => $externalReference, 'asaasInvoiceId' => $asaasNfId, 'event' => $event], 'Webhook NFS-e: fatura do WHMCS não localizada');
        return false;
    }

    try {
        $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
        if (!$invoice) {
            return false;
        }

        $eventMap = array(
            'INVOICE_CREATED' => 'agendada',
            'INVOICE_SYNCHRONIZED' => 'sincronizada com a prefeitura',
            'INVOICE_AUTHORIZED' => 'autorizada e emitida',
            'INVOICE_ERROR' => 'com erro',
            'INVOICE_CANCELED' => 'cancelada',
            'INVOICE_CANCELLATION_DENIED' => 'cancelamento negado',
        );

        $statusLabel = isset($eventMap[$event]) ? $eventMap[$event] : 'atualizada';
        $invoiceError = null;

        if (isset($payload->invoice) && isset($payload->invoice->number)) {
            $invoiceError = $payload->invoice->number;
        }

        if ($event === 'INVOICE_ERROR' && is_object($payload) && isset($payload->error)) {
            $invoiceError = $payload->error->description ?? $payload->error->message ?? 'Erro de emissão da NFS-e';
        }

        $message = '[Asaas NF] Status: ' . strtoupper($event) . ' - Nota fiscal ' . $statusLabel;
        if (!empty($invoiceError)) {
            $message .= ' | Detalhe: ' . (string) $invoiceError;
        }

        $existingNotes = trim((string) ($invoice->notes ?? ''));

        // O Asaas reenvia o mesmo evento em caso de timeout; não duplica a linha de status.
        if (preg_match_all('/^.*\[Asaas NF\].*$/m', $existingNotes, $nfLines) && end($nfLines[0]) === $message) {
            return true;
        }

        $newNotes = $existingNotes !== '' ? $existingNotes . PHP_EOL . $message : $message;

        Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->update(['notes' => $newNotes]);

        if ($invoiceError) {
            logTransaction('asaas', ['invoiceId' => $invoiceId, 'event' => $event, 'payload' => $payload], 'Webhook NFS-e: ' . $event . ' - ' . $invoiceError);
        } else {
            logTransaction('asaas', ['invoiceId' => $invoiceId, 'event' => $event], 'Webhook NFS-e: ' . $event);
        }

        return true;
    } catch (\Exception $e) {
        logTransaction('asaas', ['externalReference' => $externalReference, 'event' => $event, 'error' => $e->getMessage()], 'Erro Processamento Webhook NF');
        return false;
    }
}

function asaasAppendInvoiceNote($invoiceId, $message)
{
    $notes = trim((string) Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->value('notes'));
    if (strpos($notes, $message) !== false) {
        return;
    }
    Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->update(['notes' => $notes !== '' ? $notes . PHP_EOL . $message : $message]);
}

/**
 * Autorização ativada: o QR Code da primeira fatura foi pago. O pagamento imediato não traz
 * externalReference, então a baixa dessa fatura é feita aqui, pelo vínculo gravado na criação.
 */
function asaasPixAutomaticActivated($authorization, $gatewayParams)
{
    $table = asaas_pixauto_table();
    $invoice = Capsule::table('tblinvoices')->where('id', (int) $authorization->invoiceid)->first();
    $transactionId = 'pixauto-' . $authorization->authorization_id;

    if ($invoice && $invoice->status === 'Unpaid' && !Capsule::table('tblaccounts')->where('transid', $transactionId)->exists()) {
        addInvoicePayment((int) $invoice->id, $transactionId, (float) $authorization->value, 0, 'asaas');
        asaasUpdateServiceStatusByInvoice((int) $invoice->id, 'Active');
    }

    // Uma autorização ativa por cliente: as anteriores são canceladas no Asaas.
    $older = Capsule::table($table)
        ->where('userid', (int) $authorization->userid)
        ->where('sandbox', (int) $authorization->sandbox)
        ->where('status', 'ACTIVE')
        ->where('id', '!=', (int) $authorization->id)
        ->get();
    foreach ($older as $previous) {
        asaas_api_delete(asaas_api_url($gatewayParams), trim((string) $gatewayParams['apiKey']), '/pix/automatic/authorizations/' . rawurlencode((string) $previous->authorization_id));
        Capsule::table($table)->where('id', $previous->id)->update(['status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')]);
    }
}

function asaasHandlePixAutomaticEvent($event, $data, $gatewayParams)
{
    $table = asaas_pixauto_table();

    if (isset($data->authorization->id)) {
        $authorizationId = (string) $data->authorization->id;
        $authorization = $table ? Capsule::table($table)->where('authorization_id', $authorizationId)->first() : null;
        if (!$authorization) {
            logTransaction($gatewayParams['name'], ['event' => $event, 'authorization' => $authorizationId], 'Pix Automático: autorização não criada por este WHMCS');
            return;
        }

        $status = strtoupper((string) ($data->authorization->status ?? ''));
        if ($status !== '') {
            Capsule::table($table)->where('id', $authorization->id)->update(['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);
            $authorization->status = $status;
        }

        if ($event === 'PIX_AUTOMATIC_RECURRING_AUTHORIZATION_ACTIVATED') {
            asaasPixAutomaticActivated($authorization, $gatewayParams);
        }

        logTransaction($gatewayParams['name'], [
            'event' => $event,
            'authorization' => $authorizationId,
            'invoiceId' => $authorization->invoiceid,
            'cancellationReason' => $data->authorization->cancellationReason ?? null,
        ], 'Pix Automático: ' . $event);
        return;
    }

    if (isset($data->paymentInstruction)) {
        $paymentId = (string) ($data->paymentInstruction->paymentId ?? '');
        $invoiceId = null;
        if ($paymentId !== '') {
            $payment = asaas_api_get(asaas_api_url($gatewayParams), trim((string) $gatewayParams['apiKey']), '/payments/' . rawurlencode($paymentId));
            $invoiceId = isset($payment->externalReference) && ctype_digit((string) $payment->externalReference) ? (int) $payment->externalReference : null;
        }

        if ($invoiceId && $event === 'PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_REFUSED') {
            asaasAppendInvoiceNote($invoiceId, '[Asaas Pix Automático] Débito recusado pelo banco do cliente (cobrança ' . $paymentId . ', vencimento ' . ($data->paymentInstruction->dueDate ?? '-') . ')');
        }

        logTransaction($gatewayParams['name'], [
            'event' => $event,
            'payment' => $paymentId,
            'invoiceId' => $invoiceId,
            'status' => $data->paymentInstruction->status ?? null,
        ], 'Pix Automático: ' . $event);
        return;
    }

    logTransaction($gatewayParams['name'], $data, 'Pix Automático: ' . $event);
}

$gatewayModuleName = 'asaas';

// Buscar configurações do gateway
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// ====================================================================================
// VALIDAÇÃO DO TOKEN DO WEBHOOK
// ====================================================================================

// Obter o token configurado no painel do WHMCS
$configuredToken = isset($gatewayParams['webhookToken']) ? trim($gatewayParams['webhookToken']) : '';

// Obter o token enviado pelo Asaas no cabeçalho (Header)
// Nota: O PHP converte "asaas-access-token" para "HTTP_ASAAS_ACCESS_TOKEN"
$receivedToken = isset($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']) ? trim($_SERVER['HTTP_ASAAS_ACCESS_TOKEN']) : '';

// 1. Verifica se o administrador configurou o token no WHMCS
if (empty($configuredToken)) {
    logTransaction($gatewayParams['name'], $_SERVER, 'Erro de Webhook: Token não configurado no painel WHMCS.');
    http_response_code(401); // Não autorizado
    die('Unauthorized: Webhook token is not configured in WHMCS.');
}

// 2. Valida se o token recebido é igual ao configurado (usando hash_equals para evitar ataques de timing)
if (!hash_equals($configuredToken, $receivedToken)) {
    // Registra qual evento foi perdido, sem gravar cabeçalhos/cookies da requisição.
    $rejected = json_decode((string) file_get_contents('php://input'));
    logTransaction($gatewayParams['name'], [
        'event' => $rejected->event ?? null,
        'eventId' => $rejected->id ?? null,
        'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'remoteAddr' => $_SERVER['REMOTE_ADDR'] ?? '',
        'tokenRecebido' => $receivedToken === '' ? 'ausente' : 'presente (' . strlen($receivedToken) . ' caracteres)',
        'tokenConfigurado' => strlen($configuredToken) . ' caracteres',
    ], 'Erro de Webhook: Token de autenticação inválido ou ausente. Confira se o token do webhook no Asaas é o mesmo do WHMCS.');
    http_response_code(403); // Proibido
    die('Forbidden: Invalid Webhook Token.');
}

// ====================================================================================
// FIM DA VALIDAÇÃO (Se passou, a requisição é legítima)
// ====================================================================================

// Receber o JSON do Asaas
$jsonPayload = file_get_contents('php://input');
$data = json_decode($jsonPayload);

// Validar se há dados
if (!isset($data->event)) {
    http_response_code(400);
    die("Invalid Callback Data");
}

$event = $data->event;

if (strpos((string) $event, 'PIX_AUTOMATIC_RECURRING_') === 0) {
    if (!function_exists('asaas_pixauto_table')) {
        require_once __DIR__ . '/../asaas.php';
    }
    // Responde 200 mesmo com erro interno, para o Asaas não pausar a fila do webhook.
    try {
        asaasHandlePixAutomaticEvent($event, $data, $gatewayParams);
    } catch (\Exception $e) {
        logTransaction($gatewayParams['name'], ['event' => $event, 'error' => $e->getMessage()], 'Erro Processamento Pix Automático');
    }
    echo 'Evento de Pix Automático recebido';
    exit;
}

$payment = isset($data->payment) ? $data->payment : null;
$subscription = isset($data->subscription) ? $data->subscription : null;
$invoiceObj = isset($data->invoice) ? $data->invoice : (isset($data->object) ? $data->object : null);

$invoiceId = null;
$transactionId = null;
$amount = 0;
$fee = 0;

if ($payment) {
    $invoiceId = isset($payment->externalReference) ? $payment->externalReference : null;
    $transactionId = isset($payment->id) ? $payment->id : null;
    $amount = isset($payment->value) ? (float) $payment->value : 0;
    $fee = isset($payment->netValue) ? ((float) $payment->value - (float) $payment->netValue) : 0;
}

if (!$invoiceId && $subscription) {
    $invoiceId = isset($subscription->externalReference) ? $subscription->externalReference : null;
    $transactionId = isset($subscription->id) ? $subscription->id : null;
}

if (!$invoiceId && $invoiceObj) {
    $invoiceId = isset($invoiceObj->externalReference) ? $invoiceObj->externalReference : (isset($invoiceObj->external_reference) ? $invoiceObj->external_reference : null);
    if (!$transactionId && isset($invoiceObj->id)) {
        $transactionId = $invoiceObj->id;
    }
}

if ($invoiceObj && in_array($event, ['INVOICE_CREATED', 'INVOICE_SYNCHRONIZED', 'INVOICE_AUTHORIZED', 'INVOICE_ERROR', 'INVOICE_CANCELED', 'INVOICE_CANCELLATION_DENIED'])) {
    asaasUpdateInvoiceNFStatusByReference($invoiceId, $event, $data);
    echo 'Evento de NF recebido';
    exit;
}

// Cobranças criadas fora do WHMCS (ex.: Pix recebido direto na conta) não têm fatura.
// Responde 200: um erro faz o Asaas reenviar e, após falhas seguidas, pausar a fila do webhook.
if (empty($invoiceId)) {
    logTransaction($gatewayParams['name'], $jsonPayload, 'Evento ignorado: sem externalReference de fatura do WHMCS');
    die('Evento ignorado: sem fatura do WHMCS');
}

if ($transactionId && checkCbTransID($transactionId)) {
    die("Transaction already handled");
}

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'])) {
    if (!$transactionId) {
        $transactionId = 'asaas-' . $invoiceId . '-' . time();
    }

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $amount,
        $fee,
        $gatewayModuleName
    );

    asaasUpdateServiceStatusByInvoice($invoiceId, 'Active');

    logTransaction($gatewayParams['name'], $jsonPayload, "Successful");
    echo "Pagamento Processado com Sucesso";
    exit;
}

if (in_array($event, ['SUBSCRIPTION_CREATED', 'SUBSCRIPTION_UPDATED', 'SUBSCRIPTION_INACTIVATED', 'SUBSCRIPTION_DELETED'])) {
    // O serviço é ativado pelo pagamento; a assinatura só suspende quando é encerrada no Asaas.
    $subscriptionStatus = strtoupper((string) ($subscription->status ?? ''));
    $subscriptionEnded = in_array($event, ['SUBSCRIPTION_INACTIVATED', 'SUBSCRIPTION_DELETED'])
        || ($event === 'SUBSCRIPTION_UPDATED' && in_array($subscriptionStatus, ['INACTIVE', 'EXPIRED']));
    if ($subscriptionEnded) {
        asaasUpdateServiceStatusByInvoice($invoiceId, 'Suspended');
    }
    logTransaction($gatewayParams['name'], $jsonPayload, "Subscription Event: " . $event);
    echo "Evento de assinatura recebido";
    exit;
}

logTransaction($gatewayParams['name'], $jsonPayload, "Event: " . $event);
echo "Evento recebido (não é pagamento)";
?>
