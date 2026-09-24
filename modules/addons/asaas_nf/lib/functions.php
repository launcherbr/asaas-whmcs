<?php
/**
 * Funções do addon Asaas NF compartilhadas entre asaas_nf.php e hooks.php.
 * Carregado sempre com require_once para não haver redeclaração.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function asaas_nf_get_settings($vars = array())
{
    if (!empty($vars)) {
        return $vars;
    }

    $settings = array();

    try {
        foreach (Capsule::table('tbladdonmodules')->where('module', 'asaas_nf')->get() as $row) {
            if (isset($row->setting)) {
                $settings[$row->setting] = $row->value;
            }
        }
    } catch (\Exception $e) {
        $settings = array();
    }

    return $settings;
}

function asaas_nf_should_emit_automatically($eventName, $vars = array())
{
    $settings = asaas_nf_get_settings($vars);
    $mode = strtolower((string) ($settings['emissionMode'] ?? 'manual'));
    $trigger = strtolower((string) ($settings['automaticTrigger'] ?? 'manual'));

    if ($mode !== 'automatic') {
        return false;
    }

    $productionErrors = asaas_nf_validate_production_requirements($settings);
    if (!empty($productionErrors)) {
        return false;
    }

    $eventName = strtolower((string) $eventName);
    return $trigger !== 'manual' && $eventName === $trigger;
}

function asaas_nf_maybe_emit_by_event($invoiceId, $eventName, $vars = array())
{
    if (empty($invoiceId) || !asaas_nf_should_emit_automatically($eventName, $vars)) {
        return false;
    }

    $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
    if (!$invoice) {
        return false;
    }

    if (asaas_nf_invoice_has_nf_record((int) $invoiceId)) {
        return false;
    }

    $status = strtolower((string) ($invoice->status ?? ''));
    if ($eventName === 'payment_received' && $status !== 'paid') {
        return false;
    }

    $result = asaas_nf_emit_invoice((int) $invoiceId, $vars);
    if (function_exists('logActivity') && is_array($result) && ($result['status'] ?? '') !== 'success') {
        logActivity('Asaas NF: emissão automática da fatura #' . (int) $invoiceId . ' não realizada: ' . (string) ($result['message'] ?? ''));
    }

    return $result;
}

function asaas_nf_hook_invoice_created($vars)
{
    $invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
    if (!$invoiceId) {
        return false;
    }

    return asaas_nf_maybe_emit_by_event($invoiceId, 'invoice_created', asaas_nf_get_settings());
}

/**
 * O WHMCS não possui hook de vencimento; o cron diário emite as faturas que vencem no dia.
 */
function asaas_nf_hook_daily_due_date($vars)
{
    $settings = asaas_nf_get_settings();
    if (!asaas_nf_should_emit_automatically('due_date', $settings)) {
        return false;
    }

    $invoiceIds = Capsule::table('tblinvoices')
        ->where('duedate', date('Y-m-d'))
        ->whereIn('status', array('Unpaid', 'Paid'))
        ->whereRaw('LOWER(COALESCE(notes, "")) NOT LIKE ?', array('%asaas nf%'))
        ->limit(200)
        ->pluck('id');

    foreach ($invoiceIds as $invoiceId) {
        asaas_nf_maybe_emit_by_event((int) $invoiceId, 'due_date', $settings);
    }

    return true;
}

function asaas_nf_hook_invoice_paid($vars)
{
    $invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
    if (!$invoiceId) {
        return false;
    }

    return asaas_nf_maybe_emit_by_event($invoiceId, 'payment_received', asaas_nf_get_settings());
}

function asaas_nf_get_custom_document($clientId)
{
    $document = '';

    // Usa o mesmo campo de CPF/CNPJ configurado no gateway, para os dois módulos
    // localizarem o mesmo cliente no Asaas.
    try {
        $gatewayField = trim((string) Capsule::table('tblpaymentgateways')
            ->where('gateway', 'asaas')
            ->where('setting', 'cpfField')
            ->value('value'));
        if ($gatewayField !== '') {
            $value = Capsule::table('tblcustomfieldsvalues')
                ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
                ->where('tblcustomfields.type', 'client')
                ->where('tblcustomfields.fieldname', $gatewayField)
                ->where('tblcustomfieldsvalues.relid', (int) $clientId)
                ->value('tblcustomfieldsvalues.value');
            $document = preg_replace('/[^0-9]/', '', (string) $value);
            if ($document !== '') {
                return $document;
            }
        }
    } catch (\Exception $e) {
        $document = '';
    }

    try {
        $customFields = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->select('id', 'fieldname')
            ->get();

        foreach ($customFields as $field) {
            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $field->id)
                ->where('relid', $clientId)
                ->value('value');

            if (empty($value)) {
                continue;
            }

            $normalized = strtolower((string) $field->fieldname);
            if (strpos($normalized, 'cpf') !== false || strpos($normalized, 'cnpj') !== false || strpos($normalized, 'document') !== false) {
                $document = preg_replace('/[^0-9]/', '', (string) $value);
                if (!empty($document)) {
                    break;
                }
            }
        }
    } catch (\Exception $e) {
        $document = '';
    }

    return $document;
}

function asaas_nf_get_company_name($clientId)
{
    $companyName = '';

    try {
        $customFields = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->select('id', 'fieldname')
            ->get();

        foreach ($customFields as $field) {
            $normalized = strtolower((string) $field->fieldname);
            $isCompanyField = strpos($normalized, 'razao') !== false || strpos($normalized, 'razão') !== false || strpos($normalized, 'company') !== false || strpos($normalized, 'empresa') !== false || strpos($normalized, 'social') !== false;

            if (!$isCompanyField) {
                continue;
            }

            $value = Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $field->id)
                ->where('relid', $clientId)
                ->value('value');

            if (!empty($value)) {
                $companyName = trim((string) $value);
                if ($companyName !== '') {
                    break;
                }
            }
        }
    } catch (\Exception $e) {
        $companyName = '';
    }

    if ($companyName === '') {
        $companyName = trim((string) Capsule::table('tblclients')->where('id', (int) $clientId)->value('companyname'));
    }

    return $companyName;
}

function asaas_nf_get_customer_data($invoiceId)
{
    $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
    if (!$invoice) {
        return array('error' => 'Invoice não encontrada.');
    }

    $client = Capsule::table('tblclients')->where('id', (int) $invoice->userid)->first();
    if (!$client) {
        return array('error' => 'Cliente não encontrado.');
    }

    $doc = asaas_nf_get_custom_document((int) $client->id);
    $companyName = asaas_nf_get_company_name((int) $client->id);
    $customerName = trim((string) ($client->firstname ?? '') . ' ' . (string) ($client->lastname ?? ''));

    if ($companyName !== '') {
        $customerName = $companyName;
    }

    return array(
        'clientId' => (int) $client->id,
        'name' => $customerName,
        'email' => (string) $client->email,
        'document' => $doc,
        'address1' => trim((string) ($client->address1 ?? '')),
        'address2' => trim((string) ($client->address2 ?? '')),
        'city' => trim((string) ($client->city ?? '')),
        'state' => trim((string) ($client->state ?? '')),
        'postcode' => preg_replace('/[^0-9]/', '', (string) ($client->postcode ?? '')),
        'phone' => (string) ($client->phonenumber ?? ''),
        'companyName' => $companyName,
    );
}

function asaas_nf_validate_required_nf_fields($customer, $lineItems)
{
    $errors = array();

    $customerName = trim((string) ($customer['name'] ?? ''));
    if ($customerName === '') {
        $errors[] = 'Nome do cliente/empresa';
    }

    $document = preg_replace('/[^0-9]/', '', (string) ($customer['document'] ?? ''));
    if ($document === '') {
        $errors[] = 'CPF/CNPJ do cliente';
    }

    $email = trim((string) ($customer['email'] ?? ''));
    if ($email === '') {
        $errors[] = 'E-mail do cliente';
    }

    foreach ($lineItems as $index => $item) {
        $description = trim((string) ($item['description'] ?? ''));
        if ($description === '') {
            $errors[] = 'Descrição do Serviço (item ' . ($index + 1) . ')';
        }

        $quantity = (float) ($item['quantity'] ?? 0);
        if ($quantity <= 0) {
            $errors[] = 'Quantidade do item ' . ($index + 1);
        }

        $unitPrice = (float) ($item['unitPrice'] ?? 0);
        if ($unitPrice <= 0) {
            $errors[] = 'Valor unitário do item ' . ($index + 1);
        }
    }

    return $errors;
}

function asaas_nf_get_invoice_reference_label($invoiceId, $invoiceNumber = null)
{
    $invoiceId = (int) $invoiceId;
    $invoiceNumber = trim((string) ($invoiceNumber !== null ? $invoiceNumber : $invoiceId));

    if ($invoiceNumber === '' || $invoiceNumber === '0') {
        return 'Fatura # ' . $invoiceId;
    }

    return 'Fatura #' . $invoiceNumber;
}

function asaas_nf_invoice_has_nf_record($invoiceId)
{
    $invoiceId = (int) $invoiceId;
    if ($invoiceId <= 0) {
        return false;
    }

    try {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice || !property_exists($invoice, 'notes')) {
            return false;
        }

        $notes = trim((string) ($invoice->notes ?? ''));
        return stripos($notes, 'Asaas NF') !== false;
    } catch (\Exception $e) {
        return false;
    }
}

function asaas_nf_validate_production_requirements($vars)
{
    $errors = array();

    $apiKey = trim((string) ($vars['apiKey'] ?? ''));
    if ($apiKey === '') {
        $errors[] = 'Chave de API do Asaas';
    }

    $municipalServiceId = trim((string) ($vars['municipalServiceId'] ?? ''));
    $serviceCode = trim((string) ($vars['serviceCode'] ?? ''));
    $nationalServiceCode = trim((string) ($vars['nationalServiceCode'] ?? ''));
    if ($municipalServiceId === '' && $serviceCode === '' && $nationalServiceCode === '') {
        $errors[] = 'ID do serviço municipal ou código do serviço';
    }

    $municipalServiceName = trim((string) ($vars['municipalServiceName'] ?? ''));
    if ($municipalServiceName === '') {
        $errors[] = 'Nome do serviço padrão';
    }

    $taxesValue = trim((string) ($vars['taxes'] ?? ''));
    if ($taxesValue !== '') {
        $decodedTaxes = json_decode($taxesValue, true);
        if (!is_array($decodedTaxes)) {
            $errors[] = 'JSON de tributos válido';
        }
    }

    return $errors;
}

function asaas_nf_mark_invoice_emitted($invoiceId, $message)
{
    if (empty($invoiceId)) {
        return false;
    }

    $invoiceId = (int) $invoiceId;
    $invoiceLabel = asaas_nf_get_invoice_reference_label($invoiceId, $invoiceId);
    $noteText = '[Asaas NF] ' . $invoiceLabel . ' - Enviada em ' . date('d/m/Y H:i:s') . ' - ' . (string) $message;

    try {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if ($invoice && property_exists($invoice, 'notes')) {
            $existingNotes = trim((string) $invoice->notes);
            $combinedNotes = $existingNotes !== '' ? $existingNotes . PHP_EOL . $noteText : $noteText;
            if (stripos($existingNotes, 'Asaas NF') === false) {
                Capsule::table('tblinvoices')->where('id', $invoiceId)->update(array('notes' => $combinedNotes));
            }
        }
    } catch (\Exception $e) {
        // Sem falha crítica; registra somente em log do sistema.
    }

    if (function_exists('logActivity')) {
        logActivity('Asaas NF enviada para fatura #' . $invoiceId . ': ' . (string) $message);
    }

    return true;
}

/**
 * Chamada HTTP à API do Asaas. Toda requisição fica registrada no Log de Módulos do WHMCS.
 */
function asaas_nf_api_request($method, $apiUrl, $path, $apiKey, $body = null)
{
    $url = rtrim((string) $apiUrl, '/') . '/' . ltrim((string) $path, '/');
    $jsonBody = null;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: WHMCS-Asaas-NF',
        'access_token: ' . $apiKey,
    ));

    if ($method === 'POST') {
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($jsonBody === false) {
            curl_close($ch);
            return array('httpCode' => 0, 'body' => null, 'raw' => '', 'error' => 'Falha ao montar o JSON da requisição: ' . json_last_error_msg());
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    }

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (function_exists('logModuleCall')) {
        logModuleCall('asaas_nf', $method . ' ' . $path, $jsonBody !== null ? $jsonBody : $url, $response, null, array($apiKey));
    }

    return array(
        'httpCode' => $httpCode,
        'body' => is_string($response) ? json_decode($response, true) : null,
        'raw' => (string) $response,
        'error' => $curlError !== '' ? $curlError : null,
    );
}

function asaas_nf_api_error_message($result, $default)
{
    if (!empty($result['error'])) {
        return (string) $result['error'];
    }

    $body = $result['body'] ?? null;
    if (is_array($body) && !empty($body['errors']) && is_array($body['errors'])) {
        $messages = array();
        foreach ($body['errors'] as $error) {
            if (is_array($error) && !empty($error['description'])) {
                $messages[] = (string) $error['description'];
            }
        }
        if (!empty($messages)) {
            return implode(' | ', $messages);
        }
    }

    return $default . ' (HTTP ' . (int) ($result['httpCode'] ?? 0) . ')';
}

function asaas_nf_is_success($result)
{
    return empty($result['error']) && $result['httpCode'] >= 200 && $result['httpCode'] < 300;
}

function asaas_nf_get_fiscal_config($apiUrl, $apiKey)
{
    $result = asaas_nf_api_request('GET', $apiUrl, '/fiscalInfo', $apiKey);
    if (!asaas_nf_is_success($result)) {
        return array('error' => 'Configure as informações fiscais da conta no Asaas antes de emitir a NFS-e.');
    }

    if (!is_array($result['body'])) {
        return array('error' => 'Não foi possível recuperar a configuração fiscal da conta Asaas.');
    }

    return $result['body'];
}

function asaas_nf_sanitize_service_description($value)
{
    if (is_array($value) || is_object($value)) {
        $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $value = trim((string) $value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = strip_tags($value);
    if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
    $value = preg_replace('/\s+/u', ' ', $value);
    $value = trim((string) $value);

    return $value;
}

function asaas_nf_truncate($value, $maxLength)
{
    $value = (string) $value;
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') > $maxLength) {
        return rtrim(mb_substr($value, 0, $maxLength - 3, 'UTF-8')) . '...';
    }

    return $value;
}

/**
 * Descrição da nota (serviceDescription / discriminação). É aqui que vão os detalhes
 * de cada fatura (produto, domínio, período); o nome do serviço municipal é sempre fixo.
 */
function asaas_nf_build_service_description($invoiceLabel, $defaultDescription, $lineItems = array())
{
    $fallbackDescription = 'Serviço de assinatura WHMCS';
    $invoiceLabelText = asaas_nf_sanitize_service_description($invoiceLabel);
    $defaultDescriptionText = asaas_nf_sanitize_service_description($defaultDescription);
    $lineDescriptions = array();

    if (is_array($lineItems) || is_object($lineItems)) {
        foreach ($lineItems as $item) {
            $itemDescription = '';

            if (is_array($item) && isset($item['description'])) {
                $itemDescription = $item['description'];
            } elseif (is_object($item) && isset($item->description)) {
                $itemDescription = $item->description;
            }

            $itemDescription = asaas_nf_sanitize_service_description($itemDescription);
            if ($itemDescription !== '' && !in_array($itemDescription, $lineDescriptions, true)) {
                $lineDescriptions[] = $itemDescription;
            }
        }
    }

    $selectedDescription = !empty($lineDescriptions) ? implode('; ', $lineDescriptions) : $defaultDescriptionText;
    if ($selectedDescription === '') {
        $selectedDescription = $fallbackDescription;
    }

    $parts = array();
    if ($invoiceLabelText !== '') {
        $parts[] = $invoiceLabelText;
    }
    $parts[] = $selectedDescription;

    return asaas_nf_truncate(implode(' - ', $parts), 1000);
}

/**
 * Procura, sem criar nada, um serviço municipal já cadastrado no Asaas cujo nome coincida.
 * Contas do Portal Nacional não possuem lista de serviços; nesse caso retorna vazio.
 */
function asaas_nf_find_municipal_service_by_name($apiUrl, $apiKey, $serviceName)
{
    $serviceName = trim((string) $serviceName);
    if ($serviceName === '') {
        return array();
    }

    $result = asaas_nf_api_request('GET', $apiUrl, '/fiscalInfo/services?limit=100&description=' . rawurlencode($serviceName), $apiKey);
    if (!asaas_nf_is_success($result) || !is_array($result['body']) || empty($result['body']['data'])) {
        return array();
    }

    $needle = function_exists('mb_strtolower') ? mb_strtolower($serviceName, 'UTF-8') : strtolower($serviceName);
    foreach ($result['body']['data'] as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }

        $description = trim((string) ($item['description'] ?? ''));
        $haystack = function_exists('mb_strtolower') ? mb_strtolower($description, 'UTF-8') : strtolower($description);
        $suffix = ' - ' . $needle;
        $matchesSuffix = strlen($haystack) > strlen($suffix) && substr($haystack, -strlen($suffix)) === $suffix;

        if ($haystack === $needle || $matchesSuffix) {
            return array(
                'municipalServiceId' => (string) $item['id'],
                'municipalServiceName' => $description,
            );
        }
    }

    return array();
}

function asaas_nf_get_product_service_name($lineItems)
{
    foreach ($lineItems as $item) {
        if (strtolower((string) ($item['type'] ?? '')) !== 'hosting' || empty($item['relid'])) {
            continue;
        }

        try {
            $productName = Capsule::table('tblhosting as h')
                ->join('tblproducts as p', 'h.packageid', '=', 'p.id')
                ->where('h.id', (int) $item['relid'])
                ->value('p.name');
        } catch (\Exception $e) {
            $productName = '';
        }

        $productName = asaas_nf_sanitize_service_description($productName);
        if ($productName !== '') {
            return asaas_nf_truncate($productName, 250);
        }
    }

    return '';
}

/**
 * Define o serviço municipal da nota. Nunca usa dados variáveis da fatura (domínio, período)
 * no nome do serviço, para que o Asaas não cadastre um serviço novo a cada emissão.
 */
function asaas_nf_resolve_municipal_service($apiUrl, $apiKey, $vars, $lineItems)
{
    $mode = strtolower(trim((string) ($vars['serviceMode'] ?? 'default')));
    $defaultName = asaas_nf_truncate(asaas_nf_sanitize_service_description($vars['municipalServiceName'] ?? ''), 250);
    $configuredId = trim((string) ($vars['municipalServiceId'] ?? ''));
    $serviceCode = trim((string) ($vars['serviceCode'] ?? ''));
    if ($serviceCode === '') {
        $serviceCode = trim((string) ($vars['nationalServiceCode'] ?? ''));
    }

    $serviceName = $defaultName;
    if ($mode === 'per_product') {
        $productName = asaas_nf_get_product_service_name($lineItems);
        if ($productName !== '') {
            $serviceName = $productName;
        }
    }

    if ($serviceName === '') {
        return array('error' => 'Nome do serviço padrão não configurado no addon Asaas NF.');
    }

    if ($configuredId !== '' && $serviceName === $defaultName) {
        return array(
            'municipalServiceId' => $configuredId,
            'municipalServiceName' => $serviceName,
        );
    }

    $existing = asaas_nf_find_municipal_service_by_name($apiUrl, $apiKey, $serviceName);
    if (!empty($existing['municipalServiceId'])) {
        return $existing;
    }

    if ($serviceCode === '') {
        return array('error' => 'Serviço "' . $serviceName . '" não encontrado no Asaas e nenhum código de serviço foi configurado.');
    }

    return array(
        'municipalServiceCode' => $serviceCode,
        'municipalServiceName' => $serviceName,
    );
}

function asaas_nf_get_service_tax_config($vars)
{
    $serviceTaxConfig = array(
        'nbsCode' => trim((string) ($vars['nbsCode'] ?? '')),
        'taxSituation' => trim((string) ($vars['taxSituation'] ?? '')),
        'cClassTrib' => trim((string) ($vars['cClassTrib'] ?? '')),
        'operationIndicator' => trim((string) ($vars['operationIndicator'] ?? '')),
        'retainIss' => false,
        'iss' => 0,
        'pis' => 0,
        'cofins' => 0,
        'csll' => 0,
        'inss' => 0,
        'ir' => 0,
    );

    $rawTaxes = trim((string) ($vars['taxes'] ?? ''));
    if ($rawTaxes !== '') {
        $parsedTaxes = json_decode($rawTaxes, true);
        if (is_array($parsedTaxes)) {
            $serviceTaxConfig = array_merge($serviceTaxConfig, $parsedTaxes);
        }
    }

    return $serviceTaxConfig;
}

/**
 * Objeto taxes do POST /v3/invoices, incluindo os campos da Reforma Tributária (IBS/CBS).
 */
function asaas_nf_build_taxes_payload($vars)
{
    $config = asaas_nf_get_service_tax_config($vars);

    $taxes = array(
        'retainIss' => filter_var($config['retainIss'], FILTER_VALIDATE_BOOLEAN),
        'iss' => (float) $config['iss'],
        'cofins' => (float) $config['cofins'],
        'pis' => (float) $config['pis'],
        'csll' => (float) $config['csll'],
        'inss' => (float) $config['inss'],
        'ir' => (float) $config['ir'],
    );

    // PIS/COFINS próprios da operação (pis/cofins acima são alíquotas de retenção).
    if (isset($config['operationPis']) && $config['operationPis'] !== '') {
        $taxes['operationPis'] = (float) $config['operationPis'];
    }
    if (isset($config['operationCofins']) && $config['operationCofins'] !== '') {
        $taxes['operationCofins'] = (float) $config['operationCofins'];
    }
    $pisCofinsTaxStatus = trim((string) ($config['pisCofinsTaxStatus'] ?? ''));
    if ($pisCofinsTaxStatus !== '') {
        $taxes['pisCofinsTaxStatus'] = $pisCofinsTaxStatus;
    }

    $reformFields = array(
        'nbsCode' => 'nbsCode',
        'taxSituationCode' => 'taxSituation',
        'taxClassificationCode' => 'cClassTrib',
        'operationIndicatorCode' => 'operationIndicator',
    );
    foreach ($reformFields as $apiField => $configField) {
        $value = trim((string) ($config[$configField] ?? ''));
        if ($value !== '') {
            $taxes[$apiField] = $value;
        }
    }

    return $taxes;
}

function asaas_nf_get_valid_line_items($invoiceId, $vars)
{
    $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
    $fallbackDescription = trim((string) ($vars['defaultDescription'] ?? 'Serviço de assinatura WHMCS'));
    if ($fallbackDescription === '') {
        $fallbackDescription = 'Serviço de assinatura WHMCS';
    }

    $items = Capsule::table('tblinvoiceitems')->where('invoiceid', (int) $invoiceId)->get();
    $lineItems = array();

    foreach ($items as $item) {
        $amount = (float) ($item->amount ?? 0);
        if ($amount <= 0) {
            // Créditos e descontos não entram na discriminação da nota.
            continue;
        }

        $description = trim((string) ($item->description ?? ''));
        if ($description === '') {
            $description = $fallbackDescription;
        }

        $lineItems[] = array(
            'description' => $description,
            'quantity' => 1,
            'unitPrice' => $amount,
            'total' => $amount,
            'type' => (string) ($item->type ?? ''),
            'relid' => (int) ($item->relid ?? 0),
        );
    }

    if (empty($lineItems)) {
        $lineItems[] = array(
            'description' => $fallbackDescription,
            'quantity' => 1,
            'unitPrice' => (float) ($invoice->total ?? 0),
            'total' => (float) ($invoice->total ?? 0),
            'type' => '',
            'relid' => 0,
        );
    }

    return $lineItems;
}

/**
 * Cobrança do gateway Asaas para a fatura (externalReference = id da fatura). A nota é vinculada
 * a ela para aparecer na cobrança, com o link de pagamento. Prioriza a cobrança paga, depois a
 * pendente/vencida; ignora excluídas, estornadas e canceladas.
 */
function asaas_nf_find_invoice_payment($apiUrl, $apiKey, $invoiceId)
{
    $result = asaas_nf_api_request('GET', $apiUrl, '/payments?limit=100&externalReference=' . rawurlencode((string) (int) $invoiceId), $apiKey);
    if (!asaas_nf_is_success($result) || !is_array($result['body'])) {
        return array('error' => asaas_nf_api_error_message($result, 'Não foi possível consultar a cobrança da fatura no Asaas'));
    }

    $pending = array();
    foreach (($result['body']['data'] ?? array()) as $payment) {
        if (empty($payment['id']) || !empty($payment['deleted']) || (string) ($payment['externalReference'] ?? '') !== (string) (int) $invoiceId) {
            continue;
        }

        $status = strtoupper((string) ($payment['status'] ?? ''));
        if (in_array($status, array('RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'), true)) {
            return $payment;
        }
        if (empty($pending) && in_array($status, array('PENDING', 'OVERDUE'), true)) {
            $pending = $payment;
        }
    }

    return $pending;
}

/**
 * Consulta o Asaas por notas já existentes para esta fatura, evitando duplicidade mesmo quando
 * a observação da fatura no WHMCS foi apagada ou resetada:
 * - notas do addon (externalReference = WHMCS-{fatura});
 * - notas que o próprio Asaas emitiu a partir da cobrança do gateway (payment com externalReference = {fatura}).
 * Qualquer falha de consulta bloqueia a emissão.
 */
function asaas_nf_find_existing_asaas_invoice($apiUrl, $apiKey, $invoiceId)
{
    $blockingStatus = function ($asaasInvoice) {
        $status = strtoupper((string) ($asaasInvoice['status'] ?? ''));
        return !in_array($status, array('CANCELED', 'CANCELLED', 'ERROR'), true);
    };

    $reference = 'WHMCS-' . (int) $invoiceId;
    $result = asaas_nf_api_request('GET', $apiUrl, '/invoices?limit=100&externalReference=' . rawurlencode($reference), $apiKey);
    if (!asaas_nf_is_success($result) || !is_array($result['body'])) {
        return array('error' => asaas_nf_api_error_message($result, 'Não foi possível verificar notas existentes no Asaas'));
    }

    foreach (($result['body']['data'] ?? array()) as $asaasInvoice) {
        if ((string) ($asaasInvoice['externalReference'] ?? '') === $reference && $blockingStatus($asaasInvoice)) {
            return $asaasInvoice;
        }
    }

    $payments = asaas_nf_api_request('GET', $apiUrl, '/payments?limit=100&externalReference=' . rawurlencode((string) (int) $invoiceId), $apiKey);
    if (!asaas_nf_is_success($payments) || !is_array($payments['body'])) {
        return array('error' => asaas_nf_api_error_message($payments, 'Não foi possível verificar as cobranças da fatura no Asaas'));
    }

    foreach (($payments['body']['data'] ?? array()) as $payment) {
        if (empty($payment['id']) || (string) ($payment['externalReference'] ?? '') !== (string) (int) $invoiceId) {
            continue;
        }

        $paymentInvoices = asaas_nf_api_request('GET', $apiUrl, '/invoices?limit=100&payment=' . rawurlencode((string) $payment['id']), $apiKey);
        if (!asaas_nf_is_success($paymentInvoices) || !is_array($paymentInvoices['body'])) {
            return array('error' => asaas_nf_api_error_message($paymentInvoices, 'Não foi possível verificar notas da cobrança no Asaas'));
        }

        foreach (($paymentInvoices['body']['data'] ?? array()) as $asaasInvoice) {
            if ((string) ($asaasInvoice['payment'] ?? '') === (string) $payment['id'] && $blockingStatus($asaasInvoice)) {
                return $asaasInvoice;
            }
        }
    }

    return array();
}

/**
 * Normaliza o telefone do WHMCS (ex.: +55.31999998888) para o formato do Asaas.
 */
function asaas_nf_split_phone($rawPhone)
{
    $digits = preg_replace('/[^0-9]/', '', (string) $rawPhone);
    if (strlen($digits) >= 12 && strpos($digits, '55') === 0) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 11 && $digits[2] === '9') {
        return array('mobilePhone' => $digits);
    }
    if (strlen($digits) === 10) {
        return array('phone' => $digits);
    }

    return array();
}

/**
 * Separa "Rua X, 123 - Apto 4" em logradouro, número e complemento.
 */
function asaas_nf_split_address($address1)
{
    $address1 = trim((string) $address1);
    if (preg_match('/^(.*?)[,\s]+(?:n[º°o.]?\s*)?(\d+[A-Za-z]?)(?:\s*[-,]\s*(.+))?$/iu', $address1, $matches)) {
        return array(
            'address' => trim($matches[1], " ,-"),
            'addressNumber' => $matches[2],
            'complement' => isset($matches[3]) ? trim($matches[3]) : '',
        );
    }

    return array('address' => $address1, 'addressNumber' => $address1 !== '' ? 'S/N' : '', 'complement' => '');
}

function asaas_nf_build_customer_payload($customer)
{
    $address = asaas_nf_split_address($customer['address1'] ?? '');
    $companyName = trim((string) ($customer['companyName'] ?? ''));

    $payload = array(
        'name' => $companyName !== '' ? $companyName : trim((string) ($customer['name'] ?? '')),
        'email' => trim((string) ($customer['email'] ?? '')),
        'cpfCnpj' => preg_replace('/[^0-9]/', '', (string) ($customer['document'] ?? '')),
        'address' => $address['address'],
        'addressNumber' => $address['addressNumber'],
        'complement' => $address['complement'],
        'province' => trim((string) ($customer['address2'] ?? '')),
        'postalCode' => preg_replace('/[^0-9]/', '', (string) ($customer['postcode'] ?? '')),
        'externalReference' => 'WHMCS-CLIENT-' . (int) ($customer['clientId'] ?? 0),
    );
    $payload = array_merge($payload, asaas_nf_split_phone($customer['phone'] ?? ''));

    return array_filter($payload, function ($value) {
        return $value !== null && $value !== '';
    });
}

/**
 * Localiza o cliente no Asaas pelo CPF/CNPJ e só cria um novo quando a busca respondeu
 * com sucesso e sem resultados. Falha na busca nunca gera cadastro. Cadastros existentes
 * recebem apenas os dados que estiverem faltando (telefone, endereço etc.).
 */
function asaas_nf_find_or_create_customer($apiUrl, $apiKey, $customer)
{
    $payload = asaas_nf_build_customer_payload($customer);
    $cpfCnpj = (string) ($payload['cpfCnpj'] ?? '');
    if ($cpfCnpj === '') {
        return '';
    }

    $lockName = 'asaas_customer_' . $cpfCnpj;
    $locked = false;
    try {
        $row = Capsule::select('SELECT GET_LOCK(?, 15) AS acquired', array($lockName));
        $locked = !empty($row) && (int) $row[0]->acquired === 1;
    } catch (\Exception $e) {
        $locked = false;
    }

    try {
        $search = asaas_nf_api_request('GET', $apiUrl, '/customers?limit=100&cpfCnpj=' . rawurlencode($cpfCnpj), $apiKey);
        if (!asaas_nf_is_success($search) || !is_array($search['body'])) {
            return '';
        }

        $found = null;
        foreach (($search['body']['data'] ?? array()) as $candidate) {
            if (!empty($candidate['deleted']) || empty($candidate['id'])) {
                continue;
            }
            $candidateRef = (string) ($candidate['externalReference'] ?? '');
            if ($found === null || $candidateRef === $payload['externalReference'] || $candidateRef === (string) (int) ($customer['clientId'] ?? 0)) {
                $found = $candidate;
            }
        }

        if ($found !== null) {
            $missing = array();
            foreach ($payload as $field => $value) {
                if (in_array($field, array('name', 'email', 'cpfCnpj'), true)) {
                    continue;
                }
                if (trim((string) ($found[$field] ?? '')) === '') {
                    $missing[$field] = $value;
                }
            }
            if (isset($missing['phone']) && !empty($found['mobilePhone'])) {
                unset($missing['phone']);
            }
            if (isset($missing['mobilePhone']) && !empty($found['phone'])) {
                unset($missing['mobilePhone']);
            }
            if (!empty($missing)) {
                asaas_nf_api_request('POST', $apiUrl, '/customers/' . rawurlencode((string) $found['id']), $apiKey, $missing);
            }

            return (string) $found['id'];
        }

        $create = asaas_nf_api_request('POST', $apiUrl, '/customers', $apiKey, $payload);
        if (asaas_nf_is_success($create) && !empty($create['body']['id'])) {
            return (string) $create['body']['id'];
        }

        return '';
    } finally {
        if ($locked) {
            try {
                Capsule::select('SELECT RELEASE_LOCK(?) AS released', array($lockName));
            } catch (\Exception $e) {
                // Lock é liberado automaticamente ao encerrar a conexão.
            }
        }
    }
}

function asaas_nf_acquire_lock($invoiceId)
{
    try {
        $row = Capsule::select('SELECT GET_LOCK(?, 0) AS acquired', array('asaas_nf_invoice_' . (int) $invoiceId));
        return !empty($row) && (int) $row[0]->acquired === 1;
    } catch (\Exception $e) {
        return true;
    }
}

function asaas_nf_release_lock($invoiceId)
{
    try {
        Capsule::select('SELECT RELEASE_LOCK(?) AS released', array('asaas_nf_invoice_' . (int) $invoiceId));
    } catch (\Exception $e) {
        // Lock é liberado automaticamente ao encerrar a conexão.
    }
}

function asaas_nf_emit_invoice($invoiceId, $vars)
{
    if (empty($invoiceId)) {
        return array('status' => 'error', 'message' => 'Informe a fatura para emissão.');
    }

    $invoiceId = (int) $invoiceId;
    if (!asaas_nf_acquire_lock($invoiceId)) {
        return array('status' => 'warning', 'message' => 'Fatura #' . $invoiceId . ': emissão já em andamento em outro processo.');
    }

    try {
        return asaas_nf_emit_invoice_locked($invoiceId, $vars);
    } finally {
        asaas_nf_release_lock($invoiceId);
    }
}

function asaas_nf_emit_invoice_locked($invoiceId, $vars)
{
    $prefix = 'Fatura #' . (int) $invoiceId . ': ';

    if (asaas_nf_invoice_has_nf_record($invoiceId)) {
        return array('status' => 'warning', 'message' => $prefix . 'já possui NF registrada no Asaas e não pode ser emitida novamente sem reiniciar o estado da nota.');
    }

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return array('status' => 'error', 'message' => $prefix . 'fatura não localizada.');
    }

    if (in_array(strtolower((string) ($invoice->status ?? '')), array('cancelled', 'draft', 'refunded', 'collections'), true)) {
        return array('status' => 'error', 'message' => $prefix . 'status "' . (string) $invoice->status . '" não permite emissão de NF.');
    }

    $invoiceTotal = (float) ($invoice->total ?? 0);
    if ($invoiceTotal <= 0) {
        return array('status' => 'error', 'message' => $prefix . 'valor da fatura é zero.');
    }

    $productionErrors = asaas_nf_validate_production_requirements($vars);
    if (!empty($productionErrors)) {
        return array(
            'status' => 'error',
            'message' => 'Configuração fiscal incompleta no módulo Asaas NF: ' . implode(', ', $productionErrors) . '. Corrija antes de emitir qualquer nota.',
        );
    }

    $customer = asaas_nf_get_customer_data($invoiceId);
    if (!empty($customer['error'])) {
        return array('status' => 'error', 'message' => $prefix . $customer['error']);
    }

    $lineItems = asaas_nf_get_valid_line_items($invoiceId, $vars);
    $validationErrors = asaas_nf_validate_required_nf_fields($customer, $lineItems);
    if (!empty($validationErrors)) {
        return array(
            'status' => 'error',
            'message' => $prefix . 'dados obrigatórios ausentes para emissão da NF: ' . implode('; ', $validationErrors) . '. Corrija os campos do cliente e dos itens da fatura antes de emitir.',
        );
    }

    $apiKey = trim((string) $vars['apiKey']);
    $apiUrl = !empty($vars['sandbox']) ? 'https://sandbox.asaas.com/api/v3' : 'https://api.asaas.com/v3';
    $endpoint = trim((string) ($vars['invoiceEndpoint'] ?? ''));
    if ($endpoint === '') {
        $endpoint = '/invoices';
    }

    $fiscalConfig = asaas_nf_get_fiscal_config($apiUrl, $apiKey);
    if (!empty($fiscalConfig['error'])) {
        return array('status' => 'error', 'message' => $fiscalConfig['error']);
    }

    $existing = asaas_nf_find_existing_asaas_invoice($apiUrl, $apiKey, $invoiceId);
    if (!empty($existing['error'])) {
        return array('status' => 'error', 'message' => $prefix . $existing['error'] . '. Emissão bloqueada por segurança.');
    }
    if (!empty($existing['id'])) {
        asaas_nf_mark_invoice_emitted($invoiceId, 'NF já existente no Asaas (' . (string) $existing['id'] . ', status ' . (string) ($existing['status'] ?? '') . ').');
        return array('status' => 'warning', 'message' => $prefix . 'já existe a NF ' . (string) $existing['id'] . ' no Asaas para esta fatura. Nenhuma nova nota foi criada.');
    }

    $municipalService = asaas_nf_resolve_municipal_service($apiUrl, $apiKey, $vars, $lineItems);
    if (!empty($municipalService['error'])) {
        return array('status' => 'error', 'message' => $prefix . $municipalService['error']);
    }

    $customerId = asaas_nf_find_or_create_customer($apiUrl, $apiKey, $customer);
    if ($customerId === '') {
        return array(
            'status' => 'error',
            'message' => $prefix . 'não foi possível localizar ou criar o cliente no Asaas com CPF/CNPJ e dados do cadastro.',
        );
    }

    $invoiceLabel = asaas_nf_get_invoice_reference_label($invoiceId, $invoice->invoicenum ?? $invoiceId);
    $serviceDescription = asaas_nf_build_service_description($invoiceLabel, $vars['defaultDescription'] ?? '', $lineItems);
    $customerName = trim((string) ($customer['name'] ?? ''));

    $invoicePayment = asaas_nf_find_invoice_payment($apiUrl, $apiKey, $invoiceId);
    if (!empty($invoicePayment['error'])) {
        return array('status' => 'error', 'message' => $prefix . $invoicePayment['error'] . '. Emissão bloqueada por segurança.');
    }

    // Com cobrança do gateway, a nota fica vinculada a ela; sem cobrança, é emitida avulsa para o cliente.
    $origin = !empty($invoicePayment['id'])
        ? array('payment' => (string) $invoicePayment['id'])
        : array('customer' => $customerId);

    $payload = array_merge($origin, array(
        'serviceDescription' => $serviceDescription,
        'observations' => asaas_nf_truncate($invoiceLabel . ' | Cliente: ' . $customerName, 250),
        'externalReference' => 'WHMCS-' . $invoiceId,
        'value' => round($invoiceTotal, 2),
        'deductions' => 0,
        'effectiveDate' => date('Y-m-d'),
        'taxes' => asaas_nf_build_taxes_payload($vars),
    ));
    $payload = array_merge($payload, $municipalService);

    $result = asaas_nf_api_request('POST', $apiUrl, $endpoint, $apiKey, $payload);

    if (asaas_nf_is_success($result)) {
        $asaasInvoiceId = (string) ($result['body']['id'] ?? '');
        $successMessage = 'Nota fiscal agendada no Asaas' . ($asaasInvoiceId !== '' ? ' (' . $asaasInvoiceId . ')' : '')
            . (!empty($origin['payment']) ? ', vinculada à cobrança ' . $origin['payment'] : ', avulsa (fatura sem cobrança no Asaas)')
            . '. Aguarde a autorização da prefeitura.';
        asaas_nf_mark_invoice_emitted($invoiceId, $successMessage);

        return array(
            'status' => 'success',
            'message' => $prefix . $successMessage,
            'response' => $result['body'],
        );
    }

    return array(
        'status' => 'error',
        'message' => $prefix . asaas_nf_api_error_message($result, 'Erro ao emitir nota fiscal'),
        'response' => $result['body'],
    );
}

/**
 * Última linha "[Asaas NF]" das notas da fatura: é ela que diz o status atual da NF,
 * já que webhook e sincronização acrescentam uma linha por mudança.
 */
function asaas_nf_get_last_nf_line($notes)
{
    if (!preg_match_all('/^.*Asaas NF.*$/mi', (string) $notes, $matches)) {
        return '';
    }

    return trim((string) end($matches[0]));
}

function asaas_nf_get_status_badge($notes)
{
    $noteText = asaas_nf_get_last_nf_line($notes);
    if ($noteText === '') {
        return '<span class="label label-default">Pendente</span>';
    }

    if (stripos($noteText, 'INVOICE_ERROR') !== false || stripos($noteText, 'Erro') !== false) {
        return '<span class="label label-danger">Erro na NF</span>';
    }

    if (stripos($noteText, 'INVOICE_AUTHORIZED') !== false || stripos($noteText, 'emitida') !== false) {
        return '<span class="label label-success">NF emitida</span>';
    }

    if (stripos($noteText, 'INVOICE_SYNCHRONIZED') !== false || stripos($noteText, 'sincronizada') !== false) {
        return '<span class="label label-info">NF sincronizada</span>';
    }

    if (stripos($noteText, 'INVOICE_CREATED') !== false || stripos($noteText, 'agendada') !== false) {
        return '<span class="label label-warning">NF agendada</span>';
    }

    if (stripos($noteText, 'INVOICE_CANCELED') !== false || stripos($noteText, 'cancelada') !== false) {
        return '<span class="label label-default">NF cancelada</span>';
    }

    if (stripos($noteText, 'Asaas NF') !== false) {
        return '<span class="label label-primary">NF atualizada</span>';
    }

    return '<span class="label label-default">Pendente</span>';
}

/**
 * Status da NFS-e no Asaas => evento de webhook equivalente, para as notas terem o mesmo formato.
 */
function asaas_nf_status_event_map()
{
    return array(
        'SCHEDULED' => array('INVOICE_CREATED', 'agendada'),
        'SYNCHRONIZED' => array('INVOICE_SYNCHRONIZED', 'sincronizada com a prefeitura'),
        'AUTHORIZED' => array('INVOICE_AUTHORIZED', 'autorizada e emitida'),
        'PROCESSING_CANCELLATION' => array('INVOICE_PROCESSING_CANCELLATION', 'em processo de cancelamento'),
        'CANCELED' => array('INVOICE_CANCELED', 'cancelada'),
        'CANCELLATION_DENIED' => array('INVOICE_CANCELLATION_DENIED', 'cancelamento negado'),
        'ERROR' => array('INVOICE_ERROR', 'com erro'),
    );
}

/**
 * Consulta a NFS-e no Asaas e registra o status atual na fatura.
 * Alternativa ao webhook, que pode falhar (token divergente, fila pausada, etc.).
 */
function asaas_nf_sync_invoice_status($invoiceId, $vars)
{
    $invoiceId = (int) $invoiceId;
    $prefix = 'Fatura #' . $invoiceId . ': ';

    $apiKey = trim((string) ($vars['apiKey'] ?? ''));
    if ($apiKey === '') {
        return array('status' => 'error', 'message' => $prefix . 'chave de API do Asaas não configurada.');
    }
    $apiUrl = !empty($vars['sandbox']) && $vars['sandbox'] !== 'off' ? 'https://sandbox.asaas.com/api/v3' : 'https://api.asaas.com/v3';

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if (!$invoice) {
        return array('status' => 'error', 'message' => $prefix . 'fatura não localizada.');
    }
    $notes = trim((string) ($invoice->notes ?? ''));

    // O id da NF é gravado nas notas na emissão; sem ele, procura pela referência da fatura.
    $asaasInvoice = null;
    if (preg_match_all('/\b(inv_[A-Za-z0-9]+)\b/', $notes, $ids)) {
        $result = asaas_nf_api_request('GET', $apiUrl, '/invoices/' . rawurlencode((string) end($ids[1])), $apiKey);
        if (!asaas_nf_is_success($result) || !is_array($result['body'])) {
            return array('status' => 'error', 'message' => $prefix . asaas_nf_api_error_message($result, 'Não foi possível consultar a NF no Asaas'));
        }
        $asaasInvoice = $result['body'];
    } else {
        $existing = asaas_nf_find_existing_asaas_invoice($apiUrl, $apiKey, $invoiceId);
        if (!empty($existing['error'])) {
            return array('status' => 'error', 'message' => $prefix . $existing['error']);
        }
        $asaasInvoice = !empty($existing['id']) ? $existing : null;
    }

    if (empty($asaasInvoice['status'])) {
        return array('status' => 'warning', 'message' => $prefix . 'nenhuma NF localizada no Asaas para esta fatura.');
    }

    $asaasStatus = strtoupper((string) $asaasInvoice['status']);
    $map = asaas_nf_status_event_map();
    list($event, $label) = isset($map[$asaasStatus]) ? $map[$asaasStatus] : array('INVOICE_' . $asaasStatus, 'atualizada');

    $detail = $asaasStatus === 'ERROR'
        ? trim((string) ($asaasInvoice['statusDescription'] ?? ''))
        : trim((string) ($asaasInvoice['number'] ?? ''));
    $message = '[Asaas NF] Status: ' . $event . ' - Nota fiscal ' . $label;
    if ($detail !== '') {
        $message .= ' | Detalhe: ' . $detail;
    }

    // Só grava quando o status mudou em relação à última linha registrada.
    if (stripos(asaas_nf_get_last_nf_line($notes), 'Status: ' . $event . ' ') !== false) {
        return array('status' => 'success', 'message' => $prefix . 'NF ' . $label . ' (sem alteração).');
    }

    Capsule::table('tblinvoices')->where('id', $invoiceId)->update(array(
        'notes' => $notes !== '' ? $notes . PHP_EOL . $message : $message,
    ));
    if (function_exists('logActivity')) {
        logActivity('Asaas NF: status da fatura #' . $invoiceId . ' sincronizado: ' . $event);
    }

    return array('status' => 'success', 'message' => $prefix . 'NF ' . $label . ($detail !== '' ? ' (' . $detail . ')' : '') . '.');
}

/**
 * Sincroniza as faturas cuja NF ainda não chegou a um status final (agendada/sincronizada/cancelando).
 */
function asaas_nf_sync_pending_statuses($vars, $limit = 50)
{
    $rows = Capsule::table('tblinvoices')
        ->whereRaw('LOWER(COALESCE(notes, "")) LIKE ?', array('%asaas nf%'))
        ->where('date', '>=', date('Y-m-d', strtotime('-90 days')))
        ->orderByDesc('id')
        ->get(array('id', 'notes'));

    $results = array();
    foreach ($rows as $row) {
        $lastLine = asaas_nf_get_last_nf_line($row->notes);
        $isFinal = preg_match('/INVOICE_(AUTHORIZED|CANCELED|CANCELLATION_DENIED|ERROR)\b/i', $lastLine)
            || stripos($lastLine, 'emitida') !== false;
        if ($isFinal) {
            continue;
        }

        $results[] = asaas_nf_sync_invoice_status((int) $row->id, $vars);
        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}

function asaas_nf_hook_daily_sync_status($vars)
{
    $settings = asaas_nf_get_settings();
    if (trim((string) ($settings['apiKey'] ?? '')) === '') {
        return false;
    }

    asaas_nf_sync_pending_statuses($settings);
    return true;
}

function asaas_nf_reset_nf_state($invoiceId)
{
    if (empty($invoiceId)) {
        return array('status' => 'error', 'message' => 'Informe a fatura para reiniciar o estado da NFS-e.');
    }

    $invoiceId = (int) $invoiceId;
    try {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return array('status' => 'error', 'message' => 'Fatura não localizada.');
        }

        $existingNotes = trim((string) ($invoice->notes ?? ''));
        $newNotes = preg_replace('/\[Asaas NF\].*$/ms', '', $existingNotes);
        $newNotes = trim((string) $newNotes);

        Capsule::table('tblinvoices')->where('id', $invoiceId)->update(array('notes' => $newNotes));

        return array(
            'status' => 'success',
            'message' => 'Estado da NFS-e da fatura foi resetado. Você pode tentar emitir novamente.',
        );
    } catch (\Exception $e) {
        return array(
            'status' => 'error',
            'message' => 'Não foi possível resetar o estado da NF: ' . $e->getMessage(),
        );
    }
}
