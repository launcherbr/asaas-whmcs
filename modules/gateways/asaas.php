<?php
/**
 * Módulo Asaas para WHMCS (API V3)
 * Desenvolvido por Launcher Tech.
 * Suporta: Cobrança avulsa, assinatura recorrente e Pix Automático (débito recorrente autorizado).
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function asaas_MetaData()
{
    return array(
        'DisplayName' => 'Asaas - Pix/Boleto/Cartão',
        'APIVersion' => '2.0',
        'Description' => 'Receba via Pix, Boleto, Cartão e assinaturas recorrentes com baixa automática e gestão de clientes (API V3).',
        'Author' => 'Launcher Tech',
    );
}

function asaas_get_cycle_from_billing_cycle($billingCycle)
{
    $normalized = strtolower(trim((string) $billingCycle));
    $map = array(
        'daily' => 'DAILY',
        'weekly' => 'WEEKLY',
        'biweekly' => 'BIWEEKLY',
        'monthly' => 'MONTHLY',
        'quarterly' => 'QUARTERLY',
        'semiannually' => 'SEMIANNUALLY',
        'semiannual' => 'SEMIANNUALLY',
        'yearly' => 'YEARLY',
        'annual' => 'YEARLY',
        'annually' => 'YEARLY',
    );

    return isset($map[$normalized]) ? $map[$normalized] : 'MONTHLY';
}

function asaas_config()
{
    $customFields = array();
    try {
        foreach (Capsule::table('tblcustomfields')->where('type', 'client')->get() as $field) {
            $customFields[$field->fieldname] = $field->fieldname;
        }
    } catch (\Exception $e) {
        $customFields['CPF'] = 'CPF';
    }

    $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
    $callbackUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/asaas.php';

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Asaas (Pix, Boleto, Cartão, Assinatura)',
        ),
        'apiKey' => array(
            'FriendlyName' => 'Chave de API (API Key)',
            'Type' => 'password',
            'Size' => '50',
            'Description' => '<br><small style="color:#777;">Disponível em: Painel Asaas > Configurações > Integrações.</small>',
        ),
        'sandbox' => array(
            'FriendlyName' => 'Modo Sandbox',
            'Type' => 'yesno',
            'Description' => '<br><small style="color:#777;">Ativar ambiente de testes (requer API Key de Sandbox).</small>',
        ),
        'paymentMode' => array(
            'FriendlyName' => 'Modo de cobrança',
            'Type' => 'dropdown',
            'Options' => array(
                'charge' => 'Cobrança avulsa',
                'subscription' => 'Assinatura',
            ),
            'Default' => 'charge',
            'Description' => '<br><small style="color:#777;">Escolha o comportamento padrão do gateway para a fatura atual.</small>',
        ),
        'autoPix' => array(
            'FriendlyName' => 'Pix como forma padrão',
            'Type' => 'yesno',
            'Description' => '<br><small style="color:#777;">Gera cobranças e assinaturas somente com Pix; o cliente paga cada QR Code. Para débito automático, use o Pix Automático abaixo.</small>',
        ),
        'pixAutomatico' => array(
            'FriendlyName' => 'Pix Automático',
            'Type' => 'yesno',
            'Description' => '<br><small style="color:#777;">Faturas recorrentes (mensal, trimestral, semestral ou anual): o cliente paga a primeira por um QR Code que autoriza o débito via Pix das próximas. Requer conta PJ elegível no Asaas, o hook <code>includes/hooks/asaas_pix_automatico.php</code> e o cron diário do WHMCS. Tem prioridade sobre o modo Assinatura nessas faturas.</small>',
        ),
        'cpfField' => array(
            'FriendlyName' => 'Campo de CPF/CNPJ',
            'Type' => 'dropdown',
            'Options' => $customFields,
            'Description' => '<br><small style="color:#777;">Selecione o campo do cadastro que armazena o documento do cliente.</small>',
        ),
        'razaoSocialField' => array(
            'FriendlyName' => 'Campo de Razão Social',
            'Type' => 'dropdown',
            'Options' => array_merge(array('' => 'Padrão (campo Empresa)'), $customFields),
            'Description' => '<br><small style="color:#777;">Selecione o campo de razão social para CNPJ. Se estiver vazio, o módulo usa o campo Empresa do WHMCS.</small>',
        ),
        'dueDays' => array(
            'FriendlyName' => 'Dias pós-vencimento',
            'Type' => 'text',
            'Size' => '3',
            'Default' => '3',
            'Description' => '<br><small style="color:#777;">A cobrança usa o vencimento da fatura do WHMCS. Se a fatura já estiver vencida (o Asaas não aceita data passada), o vencimento será hoje + estes dias.</small>',
        ),
        'webhookToken' => array(
            'FriendlyName' => 'Token do Webhook',
            'Type' => 'password',
            'Size' => '50',
            'Default' => '',
            'Description' => '<br><small style="color:#777;">Obrigatório: insira o token gerado no painel do Asaas (mínimo de 32 caracteres, sem espaços).</small>',
        ),
        'webhookNote' => array(
            'FriendlyName' => '',
            'Type' => 'text',
            'Description' => '
                <script>
                    jQuery(document).ready(function($) {
                        $("input[name*=\'webhookNote\']").hide();
                        $("select[name*=\'cpfField\'], select[name*=\'razaoSocialField\'], select[name*=\'paymentMode\']").css({
                            "min-width": "260px",
                            "display": "inline-block"
                        });
                    });

                    function copyAsaasWebhookUrl(btn) {
                        var input = document.getElementById("asaasCallbackUrl");
                        input.select();
                        input.setSelectionRange(0, 99999);
                        navigator.clipboard.writeText(input.value).then(function() {
                            var originalHtml = jQuery(btn).html();
                            jQuery(btn).removeClass("btn-primary").addClass("btn-success").html(\'<i class="fas fa-check"></i> Copiado!\');
                            setTimeout(function() {
                                jQuery(btn).removeClass("btn-success").addClass("btn-primary").html(originalHtml);
                            }, 2500);
                        });
                    }

                    function generateAsaasWebhookToken() {
                        var chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
                        var token = "";
                        for (var i = 0; i < 36; i++) {
                            token += chars.charAt(Math.floor(Math.random() * chars.length));
                        }
                        var $tokenInput = jQuery("input[name*=\'webhookToken\']");
                        $tokenInput.val(token).attr("type", "text");
                        alert("Novo Token de Webhook gerado com sucesso! Lembre-se de clicar em Salvar Alterações no fim da página.");
                    }
                </script>

                <div class="alert alert-info" style="margin: 5px 0 0 0; padding: 15px 20px; border-left: 5px solid #31708f; background-color: #d9edf7; color: #31708f; border-radius: 4px;">
                    <div style="font-size: 14px; font-weight: bold; margin-bottom: 8px;">
                        <i class="fas fa-shield-alt"></i> Configuração do Webhook no Asaas (Baixa automática)
                    </div>
                    <p style="margin-bottom: 10px; font-size: 13px; line-height: 1.5; color: #245269;">
                        No painel do Asaas, acesse <strong>Configurações > Integrações > Webhooks</strong>, crie um webhook do tipo <strong>Cobranças</strong> e utilize a URL abaixo:
                    </p>

                    <div style="display: flex; gap: 8px; max-width: 100%; align-items: center; margin-bottom: 12px;">
                        <input type="text" id="asaasCallbackUrl" value="' . $callbackUrl . '" class="form-control" readonly onclick="this.select();" style="background-color: #fff; font-family: monospace; font-size: 12.5px; font-weight: 600; cursor: pointer; flex: 1;">
                        <button type="button" class="btn btn-primary" onclick="copyAsaasWebhookUrl(this)" style="min-width: 120px; font-weight: 600;">
                            <i class="fas fa-copy"></i> Copiar URL
                        </button>
                    </div>

                    <div style="font-size: 12.5px; color: #245269; background: rgba(255, 255, 255, 0.7); padding: 8px 12px; border-radius: 4px; display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <i class="fas fa-key"></i>
                        <span>Use o mesmo <strong>Token do Webhook</strong> informado no painel do Asaas e no campo acima.</span>
                        <button type="button" class="btn btn-default btn-xs" onclick="generateAsaasWebhookToken()" style="font-weight: 600;">
                            <i class="fas fa-magic"></i> Gerar token
                        </button>
                    </div>

                    <div style="margin-top: 12px; font-size: 12.5px; line-height: 1.5; color: #245269;">
                        <i class="fas fa-money-bill-wave"></i> <strong>Pagamentos:</strong> ative <code>PAYMENT_CONFIRMED</code> e <code>PAYMENT_RECEIVED</code> para dar baixa na fatura e ativar os serviços dela.
                    </div>

                    <div style="margin-top: 8px; font-size: 12.5px; line-height: 1.5; color: #245269;">
                        <i class="fas fa-sync-alt"></i> <strong>Assinaturas:</strong> ative <code>SUBSCRIPTION_INACTIVATED</code>, <code>SUBSCRIPTION_DELETED</code> e <code>SUBSCRIPTION_UPDATED</code> para suspender os serviços da fatura quando a assinatura for encerrada no Asaas. <code>SUBSCRIPTION_CREATED</code> é opcional (só registra no log).
                    </div>

                    <div style="margin-top: 8px; font-size: 12.5px; line-height: 1.5; color: #245269;">
                        <i class="fas fa-qrcode"></i> <strong>Pix Automático</strong> (se a opção estiver ativa): ative os eventos <code>PIX_AUTOMATIC_RECURRING_AUTHORIZATION_*</code> (criada, ativada, cancelada, expirada e recusada) e <code>PIX_AUTOMATIC_RECURRING_PAYMENT_INSTRUCTION_REFUSED</code>. A ativação da autorização dá baixa na primeira fatura; as seguintes são baixadas pelos eventos de pagamento.
                    </div>

                    <div style="margin-top: 8px; font-size: 12.5px; line-height: 1.5; color: #245269;">
                        <i class="fas fa-file-invoice"></i> <strong>Nota fiscal (addon Asaas NF):</strong> os status da NFS-e chegam nesta mesma URL e com o mesmo token. Ative os eventos de nota fiscal no webhook acima, ou crie outro webhook do tipo <strong>Notas fiscais</strong> com a mesma URL e o mesmo token: <code>INVOICE_CREATED</code>, <code>INVOICE_SYNCHRONIZED</code>, <code>INVOICE_AUTHORIZED</code>, <code>INVOICE_ERROR</code>, <code>INVOICE_CANCELED</code> e <code>INVOICE_CANCELLATION_DENIED</code>.
                    </div>
                </div>',
        ),
    );
}

/**
 * Vencimento da cobrança: o mesmo da fatura no WHMCS. O Asaas não aceita data passada,
 * então fatura já vencida recebe hoje + "Dias pós-vencimento".
 */
function asaas_resolve_due_date($invoiceId, $dueDays)
{
    $today = date('Y-m-d');
    $whmcsDueDate = null;

    try {
        $rawDueDate = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->value('duedate');
        if (!empty($rawDueDate) && strpos((string) $rawDueDate, '0000-00-00') !== 0) {
            $timestamp = strtotime((string) $rawDueDate);
            if ($timestamp !== false && $timestamp > 0) {
                $whmcsDueDate = date('Y-m-d', $timestamp);
            }
        }
    } catch (\Exception $e) {
        $whmcsDueDate = null;
    }

    if ($whmcsDueDate !== null && $whmcsDueDate >= $today) {
        return $whmcsDueDate;
    }

    $graceDays = max(0, (int) $dueDays);
    return date('Y-m-d', strtotime('+' . $graceDays . ' days'));
}

function asaas_api_get($apiUrl, $apiKey, $path)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'access_token: ' . $apiKey,
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300) {
        return null;
    }

    return json_decode((string) $response);
}

/**
 * Cobrança já criada para a fatura (externalReference = id da fatura), para não gerar
 * uma cobrança nova a cada vez que o cliente abre a fatura.
 */
function asaas_find_invoice_payment($apiUrl, $apiKey, $invoiceId)
{
    $result = asaas_api_get($apiUrl, $apiKey, '/payments?limit=100&externalReference=' . urlencode((string) $invoiceId));
    if (!$result || empty($result->data)) {
        return null;
    }

    $pending = null;
    foreach ($result->data as $payment) {
        if (!empty($payment->deleted) || (string) ($payment->externalReference ?? '') !== (string) $invoiceId) {
            continue;
        }

        $status = strtoupper((string) ($payment->status ?? ''));
        if (in_array($status, array('RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'), true)) {
            return $payment;
        }
        if ($pending === null && in_array($status, array('PENDING', 'OVERDUE'), true)) {
            $pending = $payment;
        }
    }

    return $pending;
}

function asaas_update_payment($apiUrl, $apiKey, $paymentId, $data)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . '/payments/' . urlencode($paymentId));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'access_token: ' . $apiKey,
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string) $response);
    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded->id)) {
        return $decoded;
    }

    return null;
}

function asaas_find_active_subscription($apiUrl, $apiKey, $invoiceId)
{
    $result = asaas_api_get($apiUrl, $apiKey, '/subscriptions?limit=100&externalReference=' . urlencode((string) $invoiceId));
    if (!$result || empty($result->data)) {
        return null;
    }

    foreach ($result->data as $subscription) {
        if (empty($subscription->deleted) && strtoupper((string) ($subscription->status ?? '')) === 'ACTIVE'
            && (string) ($subscription->externalReference ?? '') === (string) $invoiceId) {
            return $subscription;
        }
    }

    return null;
}

function asaas_get_client_custom_field($clientId, $fieldName)
{
    $fieldName = trim((string) $fieldName);
    if ($fieldName === '') {
        return '';
    }

    try {
        return trim((string) Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfields.fieldname', $fieldName)
            ->where('tblcustomfieldsvalues.relid', (int) $clientId)
            ->value('tblcustomfieldsvalues.value'));
    } catch (\Exception $e) {
        return '';
    }
}

/**
 * Normaliza o telefone do WHMCS (ex.: +55.31999998888) para o formato do Asaas.
 */
function asaas_split_phone($rawPhone)
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
function asaas_split_address($address1)
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

/**
 * Localiza o cliente pelo CPF/CNPJ e só cria quando a busca respondeu com sucesso e sem
 * resultados; falha na busca nunca gera cadastro. Cadastros existentes recebem apenas os
 * dados que estiverem faltando (telefone, endereço etc.).
 */
function asaas_find_or_create_customer($apiUrl, $apiKey, $customerData, $clientId)
{
    $cpfCnpj = (string) ($customerData['cpfCnpj'] ?? '');
    $lockName = 'asaas_customer_' . $cpfCnpj;
    $locked = false;
    try {
        $row = Capsule::select('SELECT GET_LOCK(?, 15) AS acquired', array($lockName));
        $locked = !empty($row) && (int) $row[0]->acquired === 1;
    } catch (\Exception $e) {
        $locked = false;
    }

    try {
        $search = asaas_api_get($apiUrl, $apiKey, '/customers?limit=100&cpfCnpj=' . urlencode($cpfCnpj));
        if ($search === null) {
            return array('error' => 'Não foi possível consultar o cliente no Asaas. Tente novamente em instantes.');
        }

        $found = null;
        foreach (($search->data ?? array()) as $candidate) {
            if (!empty($candidate->deleted) || empty($candidate->id)) {
                continue;
            }
            $candidateRef = (string) ($candidate->externalReference ?? '');
            if ($found === null || $candidateRef === (string) ($customerData['externalReference'] ?? '') || $candidateRef === (string) (int) $clientId) {
                $found = $candidate;
            }
        }

        if ($found !== null) {
            $missing = array();
            foreach ($customerData as $field => $value) {
                if (in_array($field, array('name', 'email', 'cpfCnpj'), true)) {
                    continue;
                }
                if (trim((string) ($found->$field ?? '')) === '') {
                    $missing[$field] = $value;
                }
            }
            if (isset($missing['phone']) && !empty($found->mobilePhone)) {
                unset($missing['phone']);
            }
            if (isset($missing['mobilePhone']) && !empty($found->phone)) {
                unset($missing['mobilePhone']);
            }
            if (!empty($missing)) {
                asaas_api_post($apiUrl, $apiKey, '/customers/' . urlencode((string) $found->id), $missing);
            }

            return array('id' => (string) $found->id);
        }

        $created = asaas_api_post($apiUrl, $apiKey, '/customers', $customerData);
        if ($created['httpCode'] >= 200 && $created['httpCode'] < 300 && isset($created['body']->id)) {
            return array('id' => (string) $created['body']->id);
        }

        $description = isset($created['body']->errors[0]->description) ? (string) $created['body']->errors[0]->description : 'Erro ao cadastrar cliente no Asaas';
        return array('error' => $description, 'response' => $created['raw']);
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

function asaas_api_post($apiUrl, $apiKey, $path, $data)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'access_token: ' . $apiKey,
    ));
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array('httpCode' => $httpCode, 'body' => json_decode((string) $response), 'raw' => (string) $response);
}

function asaas_payment_button($url, $label)
{
    return '
            <div style="text-align:center; margin-top:15px;">
                <a href="' . htmlspecialchars($url) . '" target="_blank" class="btn btn-success btn-lg">
                    <i class="fas fa-lock"></i> ' . $label . '
                </a>
                <br/><small>Processado via Asaas</small>
            </div>';
}

function asaas_api_delete($apiUrl, $apiKey, $path)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . $path);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'access_token: ' . $apiKey,
    ));
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return array('httpCode' => $httpCode, 'body' => json_decode((string) $response), 'raw' => (string) $response);
}

/*
 * Pix Automático (Jornada 3, modo MANUAL)
 *
 * A primeira fatura recorrente é paga por um QR Code que também autoriza os débitos seguintes.
 * Com a autorização ACTIVE, cada fatura recorrente vira uma cobrança vinculada à autorização
 * (externalReference = id da fatura), criada entre 3 e 10 dias úteis antes do vencimento pelo
 * cron diário ou quando o cliente abre a fatura. O Asaas exige de 2 a 10 dias úteis; a margem
 * de um dia cobre feriados, que não entram na contagem.
 */
define('ASAAS_PIXAUTO_MIN_BUSINESS_DAYS', 3);
define('ASAAS_PIXAUTO_MAX_BUSINESS_DAYS', 10);

function asaas_api_url($params)
{
    return !empty($params['sandbox']) ? 'https://sandbox.asaas.com/api/v3' : 'https://www.asaas.com/api/v3';
}

/**
 * Tabela das autorizações criadas pelo módulo, criada no primeiro uso.
 */
function asaas_pixauto_table()
{
    $table = 'mod_asaas_pix_automatico';
    try {
        if (!Capsule::schema()->hasTable($table)) {
            Capsule::schema()->create($table, function ($t) {
                $t->increments('id');
                $t->integer('userid')->index();
                $t->integer('invoiceid')->index();
                $t->string('authorization_id', 64)->unique();
                $t->string('customer_id', 64);
                $t->string('status', 20)->index();
                $t->string('frequency', 20);
                $t->decimal('value', 10, 2);
                $t->boolean('sandbox')->default(false);
                $t->text('qr_payload')->nullable();
                $t->mediumText('qr_image')->nullable();
                $t->dateTime('qr_expires_at')->nullable();
                $t->timestamps();
            });
        }
    } catch (\Exception $e) {
        logTransaction('asaas', array('error' => $e->getMessage()), 'Erro ao criar tabela do Pix Automático');
        return null;
    }

    return $table;
}

/**
 * Frequência do Pix Automático conforme o ciclo dos serviços da fatura. Retorna null quando a
 * fatura não tem serviço recorrente ou mistura ciclos, e então segue o fluxo comum.
 */
function asaas_pixauto_frequency_for_invoice($invoiceId)
{
    $map = array(
        'monthly' => 'MONTHLY',
        'quarterly' => 'QUARTERLY',
        'semi-annually' => 'SEMIANNUALLY',
        'annually' => 'ANNUALLY',
    );

    try {
        $cycles = Capsule::table('tblinvoiceitems')
            ->join('tblhosting', 'tblhosting.id', '=', 'tblinvoiceitems.relid')
            ->where('tblinvoiceitems.invoiceid', (int) $invoiceId)
            ->where('tblinvoiceitems.type', 'Hosting')
            ->distinct()
            ->pluck('tblhosting.billingcycle');
    } catch (\Exception $e) {
        return null;
    }

    $frequencies = array();
    foreach ($cycles as $cycle) {
        $key = strtolower(trim((string) $cycle));
        if (!isset($map[$key])) {
            return null;
        }
        $frequencies[$map[$key]] = true;
    }

    return count($frequencies) === 1 ? key($frequencies) : null;
}

function asaas_pixauto_invoice_due_date($invoiceId)
{
    try {
        $rawDueDate = (string) Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->value('duedate');
    } catch (\Exception $e) {
        return null;
    }
    if ($rawDueDate === '' || strpos($rawDueDate, '0000-00-00') === 0) {
        return null;
    }

    return date('Y-m-d', strtotime($rawDueDate));
}

/**
 * Dias úteis (segunda a sexta) de amanhã até o vencimento, inclusive. Feriados não são descontados.
 */
function asaas_pixauto_business_days_until($date)
{
    $today = new \DateTime('today');
    $target = new \DateTime($date);
    $days = 0;
    while ($today < $target) {
        $today->modify('+1 day');
        if ((int) $today->format('N') < 6) {
            $days++;
        }
    }

    return $days;
}

/**
 * Início da vigência: o vencimento seguinte ao da fatura paga pelo QR Code.
 */
function asaas_pixauto_start_date($dueDate, $frequency)
{
    $months = array('MONTHLY' => 1, 'QUARTERLY' => 3, 'SEMIANNUALLY' => 6, 'ANNUALLY' => 12);
    $start = new \DateTime($dueDate);
    $start->modify('+' . $months[$frequency] . ' months');
    $tomorrow = new \DateTime('tomorrow');

    return ($start < $tomorrow ? $tomorrow : $start)->format('Y-m-d');
}

function asaas_pixauto_active_authorization($userId, $sandbox)
{
    $table = asaas_pixauto_table();
    if (!$table) {
        return null;
    }

    return Capsule::table($table)
        ->where('userid', (int) $userId)
        ->where('sandbox', $sandbox ? 1 : 0)
        ->where('status', 'ACTIVE')
        ->orderBy('id', 'desc')
        ->first();
}

function asaas_pixauto_create_authorization($apiUrl, $apiKey, $sandbox, $userId, $customerId, $invoiceId, $amount, $dueDate, $frequency)
{
    $table = asaas_pixauto_table();
    if (!$table) {
        return array('error' => 'Não foi possível preparar o Pix Automático.');
    }

    // O QR Code vale até o fim do dia do vencimento (mínimo de 24 horas e máximo de 30 dias).
    $expiresAt = max(strtotime($dueDate . ' 23:59:59'), time() + 86400);
    $expiresAt = min($expiresAt, time() + 30 * 86400);

    $result = asaas_api_post($apiUrl, $apiKey, '/pix/automatic/authorizations', array(
        'frequency' => $frequency,
        'contractId' => 'WHMCS-' . (int) $userId . '-' . (int) $invoiceId,
        'startDate' => asaas_pixauto_start_date($dueDate, $frequency),
        'description' => 'Servicos WHMCS cliente #' . (int) $userId,
        'customerId' => $customerId,
        'paymentCreationMode' => 'MANUAL',
        'immediateQrCode' => array(
            'expirationSeconds' => $expiresAt - time(),
            'originalValue' => round((float) $amount, 2),
            'description' => 'Fatura #' . $invoiceId,
        ),
    ));

    $body = $result['body'];
    if ($result['httpCode'] < 200 || $result['httpCode'] >= 300 || empty($body->id)) {
        $description = isset($body->errors[0]->description) ? (string) $body->errors[0]->description : 'Erro ao criar a autorização do Pix Automático';
        return array('error' => $description, 'response' => $result['raw']);
    }

    $now = date('Y-m-d H:i:s');
    Capsule::table($table)->insert(array(
        'userid' => (int) $userId,
        'invoiceid' => (int) $invoiceId,
        'authorization_id' => (string) $body->id,
        'customer_id' => (string) $customerId,
        'status' => strtoupper((string) ($body->status ?? 'CREATED')),
        'frequency' => $frequency,
        'value' => round((float) $amount, 2),
        'sandbox' => $sandbox ? 1 : 0,
        'qr_payload' => isset($body->payload) ? (string) $body->payload : null,
        'qr_image' => isset($body->encodedImage) ? (string) $body->encodedImage : null,
        'qr_expires_at' => date('Y-m-d H:i:s', $expiresAt),
        'created_at' => $now,
        'updated_at' => $now,
    ));

    return array('row' => Capsule::table($table)->where('authorization_id', (string) $body->id)->first());
}

function asaas_pixauto_create_payment($apiUrl, $apiKey, $authorization, $invoiceId, $amount, $dueDate)
{
    return asaas_api_post($apiUrl, $apiKey, '/payments', array(
        'customer' => $authorization->customer_id,
        'billingType' => 'PIX',
        'value' => round((float) $amount, 2),
        'dueDate' => $dueDate,
        'description' => 'Fatura #' . $invoiceId . ' (Pix Automático)',
        'externalReference' => (string) $invoiceId,
        'pixAutomaticAuthorizationId' => $authorization->authorization_id,
    ));
}

function asaas_pixauto_frequency_label($frequency)
{
    $labels = array('MONTHLY' => 'mensais', 'QUARTERLY' => 'trimestrais', 'SEMIANNUALLY' => 'semestrais', 'ANNUALLY' => 'anuais');

    return isset($labels[$frequency]) ? $labels[$frequency] : 'recorrentes';
}

function asaas_pixauto_qr_html($authorization)
{
    $html = '
            <div style="text-align:center; margin-top:15px;">
                <div class="alert alert-info" style="margin-bottom: 10px; text-align:left;">
                    <strong>Pague com Pix Automático</strong><br>
                    Pague esta fatura pelo QR Code abaixo. No mesmo pagamento você autoriza o débito automático via Pix das próximas faturas '
                    . asaas_pixauto_frequency_label($authorization->frequency) . ', sem precisar pagar um novo QR Code a cada vencimento. A autorização pode ser cancelada a qualquer momento pelo app do seu banco.
                </div>';

    if (!empty($authorization->qr_image)) {
        $html .= '
                <img src="data:image/png;base64,' . htmlspecialchars((string) $authorization->qr_image) . '" alt="QR Code do Pix Automático" style="max-width:220px; width:100%;">';
    }

    if (!empty($authorization->qr_payload)) {
        $html .= '
                <div style="display:flex; gap:8px; margin:10px auto; max-width:420px;">
                    <input type="text" id="asaasPixAutoPayload" value="' . htmlspecialchars((string) $authorization->qr_payload) . '" class="form-control" readonly onclick="this.select();" style="font-family:monospace; font-size:12px;">
                    <button type="button" class="btn btn-default" onclick="var i=document.getElementById(\'asaasPixAutoPayload\');i.select();document.execCommand(\'copy\');this.innerText=\'Copiado\';">Copiar</button>
                </div>';
    }

    if (!empty($authorization->qr_expires_at)) {
        $html .= '
                <small>QR Code válido até ' . htmlspecialchars(date('d/m/Y H:i', strtotime((string) $authorization->qr_expires_at))) . '.</small><br>';
    }

    return $html . '
                <small>Processado via Asaas</small>
            </div>';
}

function asaas_pixauto_scheduled_html($dueDate, $invoiceUrl)
{
    $html = '
            <div style="text-align:center; margin-top:15px;">
                <div class="alert alert-success" style="margin-bottom: 10px; text-align:left;">
                    <strong>Débito automático via Pix Automático.</strong><br>
                    Esta fatura será debitada automaticamente no vencimento (' . htmlspecialchars(date('d/m/Y', strtotime($dueDate))) . '), conforme a autorização registrada no seu banco.
                </div>';

    if ($invoiceUrl !== '') {
        $html .= '
                <a href="' . htmlspecialchars($invoiceUrl) . '" target="_blank" class="btn btn-default">Pagar agora</a><br>
                <small>Se pagar antes, o débito automático desta fatura é cancelado.</small><br>';
    }

    return $html . '
                <small>Processado via Asaas</small>
            </div>';
}

/**
 * Fluxo do Pix Automático na fatura. Retorna o HTML a exibir; false quando a fatura deve ser
 * paga por uma cobrança avulsa (vencimento próximo demais ou falha na autorização); null quando
 * a fatura não se aplica ao Pix Automático e segue o fluxo comum do gateway.
 */
function asaas_pixauto_link($params, $apiUrl, $apiKey, $clientId, $customerId, $invoiceId, $amount)
{
    $sandbox = !empty($params['sandbox']);
    $frequency = asaas_pixauto_frequency_for_invoice($invoiceId);
    $dueDate = asaas_pixauto_invoice_due_date($invoiceId);
    $table = asaas_pixauto_table();
    if ($frequency === null || $dueDate === null || !$table) {
        return null;
    }

    $authorization = asaas_pixauto_active_authorization($clientId, $sandbox);
    if ($authorization) {
        $payment = asaas_find_invoice_payment($apiUrl, $apiKey, $invoiceId);
        if (!$payment) {
            $businessDays = asaas_pixauto_business_days_until($dueDate);
            if ($businessDays > ASAAS_PIXAUTO_MAX_BUSINESS_DAYS) {
                return asaas_pixauto_scheduled_html($dueDate, '');
            }
            if ($businessDays < ASAAS_PIXAUTO_MIN_BUSINESS_DAYS) {
                return false;
            }

            $created = asaas_pixauto_create_payment($apiUrl, $apiKey, $authorization, $invoiceId, $amount, $dueDate);
            if ($created['httpCode'] < 200 || $created['httpCode'] >= 300 || empty($created['body']->id)) {
                logTransaction($params['name'], array('invoiceId' => $invoiceId, 'authorization' => $authorization->authorization_id, 'response' => $created['raw']), 'Erro Cobrança Pix Automático');
                return false;
            }
            $payment = $created['body'];
        }

        $status = strtoupper((string) ($payment->status ?? ''));
        if (in_array($status, array('RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'), true)) {
            return '<div class="alert alert-success" style="text-align:center;">Pagamento desta fatura já recebido pelo Asaas.</div>';
        }
        if (strpos((string) ($payment->description ?? ''), '(Pix Automático)') === false) {
            // Cobrança avulsa criada antes (ex.: vencimento já próximo); segue o botão comum.
            return false;
        }

        return asaas_pixauto_scheduled_html($dueDate, (string) ($payment->invoiceUrl ?? ''));
    }

    $pending = Capsule::table($table)
        ->where('invoiceid', (int) $invoiceId)
        ->where('sandbox', $sandbox ? 1 : 0)
        ->where('status', 'CREATED')
        ->where('qr_expires_at', '>', date('Y-m-d H:i:s'))
        ->orderBy('id', 'desc')
        ->first();

    // Valor da fatura mudou (crédito, item novo): o QR Code antigo cobraria o valor errado.
    if ($pending && abs((float) $pending->value - (float) $amount) >= 0.01) {
        asaas_api_delete($apiUrl, $apiKey, '/pix/automatic/authorizations/' . rawurlencode((string) $pending->authorization_id));
        Capsule::table($table)->where('id', $pending->id)->update(array('status' => 'CANCELLED', 'updated_at' => date('Y-m-d H:i:s')));
        $pending = null;
    }

    if (!$pending) {
        $created = asaas_pixauto_create_authorization($apiUrl, $apiKey, $sandbox, $clientId, $customerId, $invoiceId, $amount, $dueDate, $frequency);
        if (!empty($created['error'])) {
            logTransaction($params['name'], $created, 'Erro Autorização Pix Automático');
            return false;
        }
        $pending = $created['row'];
    }

    return asaas_pixauto_qr_html($pending);
}

/**
 * Cron diário: cria as cobranças vinculadas à autorização das faturas em aberto que entraram
 * na janela de dias úteis antes do vencimento.
 */
function asaas_pixauto_cron($params)
{
    $table = asaas_pixauto_table();
    if (!$table) {
        return;
    }

    $sandbox = !empty($params['sandbox']);
    $apiUrl = asaas_api_url($params);
    $apiKey = trim((string) ($params['apiKey'] ?? ''));

    $authorizations = Capsule::table($table)
        ->where('status', 'ACTIVE')
        ->where('sandbox', $sandbox ? 1 : 0)
        ->orderBy('id', 'desc')
        ->get();

    $seenClients = array();
    foreach ($authorizations as $authorization) {
        if (isset($seenClients[$authorization->userid])) {
            continue;
        }
        $seenClients[$authorization->userid] = true;

        $invoices = Capsule::table('tblinvoices')
            ->where('userid', (int) $authorization->userid)
            ->where('status', 'Unpaid')
            ->where('paymentmethod', 'asaas')
            ->where('duedate', '>', date('Y-m-d'))
            ->get();

        foreach ($invoices as $invoice) {
            $dueDate = date('Y-m-d', strtotime((string) $invoice->duedate));
            $businessDays = asaas_pixauto_business_days_until($dueDate);
            if ($businessDays < ASAAS_PIXAUTO_MIN_BUSINESS_DAYS || $businessDays > ASAAS_PIXAUTO_MAX_BUSINESS_DAYS) {
                continue;
            }
            if (asaas_pixauto_frequency_for_invoice($invoice->id) === null || asaas_find_invoice_payment($apiUrl, $apiKey, $invoice->id)) {
                continue;
            }

            $details = localAPI('GetInvoice', array('invoiceid' => (int) $invoice->id));
            $balance = (float) ($details['balance'] ?? 0);
            if ($balance <= 0) {
                continue;
            }

            $created = asaas_pixauto_create_payment($apiUrl, $apiKey, $authorization, $invoice->id, $balance, $dueDate);
            if ($created['httpCode'] >= 200 && $created['httpCode'] < 300 && !empty($created['body']->id)) {
                logTransaction($params['name'], array('invoiceId' => $invoice->id, 'payment' => $created['body']->id, 'authorization' => $authorization->authorization_id), 'Pix Automático: cobrança criada');
            } else {
                logTransaction($params['name'], array('invoiceId' => $invoice->id, 'authorization' => $authorization->authorization_id, 'response' => $created['raw']), 'Erro Cobrança Pix Automático');
            }
        }
    }
}

function asaas_link($params)
{
    $apiKey = trim((string) ($params['apiKey'] ?? ''));
    $apiUrl = asaas_api_url($params);

    $invoiceId = (string) ($params['invoiceid'] ?? '');
    $amount = (float) ($params['amount'] ?? 0);
    $firstname = isset($params['clientdetails']['firstname']) ? $params['clientdetails']['firstname'] : '';
    $lastname = isset($params['clientdetails']['lastname']) ? $params['clientdetails']['lastname'] : '';
    $email = isset($params['clientdetails']['email']) ? $params['clientdetails']['email'] : '';
    $clientId = isset($params['clientdetails']['id']) ? (int) $params['clientdetails']['id'] : 0;

    $paymentMode = isset($params['paymentMode']) ? strtolower((string) $params['paymentMode']) : 'charge';
    $isSubscription = ($paymentMode === 'subscription') || !empty($params['isRecurring']) || !empty($params['recurring']) || (!empty($params['billingcycle']) && strtolower((string) $params['billingcycle']) !== 'onetime');

    $cpfFieldName = isset($params['cpfField']) ? $params['cpfField'] : '';
    $rawCpf = '';
    try {
        $rawCpf = Capsule::table('tblcustomfieldsvalues')
            ->join('tblcustomfields', 'tblcustomfieldsvalues.fieldid', '=', 'tblcustomfields.id')
            ->where('tblcustomfields.fieldname', $cpfFieldName)
            ->where('tblcustomfieldsvalues.relid', $clientId)
            ->value('tblcustomfieldsvalues.value');
    } catch (\Exception $e) {
        $rawCpf = '';
    }

    $cpf = preg_replace('/[^0-9]/', '', (string) $rawCpf);
    if (empty($cpf)) {
        logTransaction($params['name'], array(
            'Erro' => 'CPF Vazio',
            'ClienteID' => $clientId,
            'CampoBuscado' => $cpfFieldName,
            'ResultadoBanco' => $rawCpf,
        ), 'Erro CPF');

        return '<div class="alert alert-danger">Erro: CPF/CNPJ (Campo: ' . htmlspecialchars($cpfFieldName) . ') não foi encontrado ou está vazio no perfil do cliente.</div>';
    }

    $address1 = isset($params['clientdetails']['address1']) ? trim((string) $params['clientdetails']['address1']) : '';
    $address2 = isset($params['clientdetails']['address2']) ? trim((string) $params['clientdetails']['address2']) : '';
    $city = isset($params['clientdetails']['city']) ? trim((string) $params['clientdetails']['city']) : '';
    $state = isset($params['clientdetails']['state']) ? trim((string) $params['clientdetails']['state']) : '';
    $postcode = isset($params['clientdetails']['postcode']) ? preg_replace('/[^0-9]/', '', (string) $params['clientdetails']['postcode']) : '';
    $country = isset($params['clientdetails']['country']) ? trim((string) $params['clientdetails']['country']) : '';

    if ($address1 === '' || $city === '' || $state === '' || $postcode === '') {
        $clientData = Capsule::table('tblclients')->where('id', (int) $clientId)->first();
        if ($clientData) {
            $address1 = $address1 !== '' ? $address1 : trim((string) ($clientData->address1 ?? ''));
            $address2 = $address2 !== '' ? $address2 : trim((string) ($clientData->address2 ?? ''));
            $city = $city !== '' ? $city : trim((string) ($clientData->city ?? ''));
            $state = $state !== '' ? $state : trim((string) ($clientData->state ?? ''));
            $postcode = $postcode !== '' ? $postcode : preg_replace('/[^0-9]/', '', (string) ($clientData->postcode ?? ''));
            $country = $country !== '' ? $country : trim((string) ($clientData->country ?? ''));
        }
    }

    $phoneNumber = isset($params['clientdetails']['phonenumber']) ? (string) $params['clientdetails']['phonenumber'] : '';
    $companyName = isset($params['clientdetails']['companyname']) ? trim((string) $params['clientdetails']['companyname']) : '';
    if ($phoneNumber === '' || $companyName === '') {
        $clientRow = Capsule::table('tblclients')->where('id', (int) $clientId)->first();
        if ($clientRow) {
            $phoneNumber = $phoneNumber !== '' ? $phoneNumber : (string) ($clientRow->phonenumber ?? '');
            $companyName = $companyName !== '' ? $companyName : trim((string) ($clientRow->companyname ?? ''));
        }
    }

    $customerName = trim($firstname . ' ' . $lastname);
    if (strlen($cpf) === 14) {
        $razaoSocial = asaas_get_client_custom_field($clientId, $params['razaoSocialField'] ?? '');
        if ($razaoSocial !== '') {
            $customerName = $razaoSocial;
        } elseif ($companyName !== '') {
            $customerName = $companyName;
        }
    }

    $addressParts = asaas_split_address($address1);
    $customerData = array(
        'name' => $customerName,
        'email' => $email,
        'cpfCnpj' => $cpf,
        'address' => $addressParts['address'],
        'addressNumber' => $addressParts['addressNumber'],
        'complement' => $addressParts['complement'],
        'province' => $address2,
        'postalCode' => $postcode,
        'externalReference' => 'WHMCS-CLIENT-' . (int) $clientId,
    );
    $customerData = array_merge($customerData, asaas_split_phone($phoneNumber));
    $customerData = array_filter($customerData, function ($value) {
        return $value !== null && $value !== '';
    });

    $customerResult = asaas_find_or_create_customer($apiUrl, $apiKey, $customerData, $clientId);
    if (!empty($customerResult['error'])) {
        logTransaction($params['name'], $customerResult, 'Erro Cadastro Cliente');
        return '<div class="alert alert-danger">Asaas: ' . htmlspecialchars($customerResult['error']) . '</div>';
    }
    $customerId = $customerResult['id'];

    if (!empty($params['pixAutomatico'])) {
        $pixAutoHtml = asaas_pixauto_link($params, $apiUrl, $apiKey, $clientId, $customerId, $invoiceId, $amount);
        if (is_string($pixAutoHtml)) {
            return $pixAutoHtml;
        }
        if ($pixAutoHtml === false) {
            // Fatura do Pix Automático fora da janela de débito: cobrança avulsa, nunca assinatura.
            $isSubscription = false;
        }
    }

    $autoPix = !empty($params['autoPix']);
    $billingType = $autoPix ? 'PIX' : 'UNDEFINED';

    $dueDate = asaas_resolve_due_date($invoiceId, $params['dueDays'] ?? 3);

    if ($isSubscription) {
        $existingSubscription = asaas_find_active_subscription($apiUrl, $apiKey, $invoiceId);
        if ($existingSubscription) {
            return '
                <div style="text-align:center; margin-top:15px;">
                    <div class="alert alert-info" style="margin-bottom: 10px; text-align:left;">
                        <strong>Assinatura já ativa no Asaas.</strong>
                        <br>
                        Próximo vencimento: ' . htmlspecialchars(date('d/m/Y', strtotime((string) ($existingSubscription->nextDueDate ?? $dueDate)))) . '.
                    </div>
                    <small>Processado via Asaas</small>
                </div>';
        }

        $subscriptionData = array(
            'customer' => $customerId,
            'billingType' => $billingType,
            'value' => $amount,
            'nextDueDate' => $dueDate,
            'cycle' => asaas_get_cycle_from_billing_cycle($params['billingcycle'] ?? ''),
            'description' => 'Assinatura WHMCS #' . $invoiceId,
            'externalReference' => $invoiceId,
            'interval' => 1,
        );

        $chSubscription = curl_init();
        curl_setopt($chSubscription, CURLOPT_URL, $apiUrl . '/subscriptions');
        curl_setopt($chSubscription, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chSubscription, CURLOPT_POST, true);
        curl_setopt($chSubscription, CURLOPT_POSTFIELDS, json_encode($subscriptionData));
        curl_setopt($chSubscription, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'access_token: ' . $apiKey,
        ));
        $responseSubscription = curl_exec($chSubscription);
        $httpCodeSubscription = curl_getinfo($chSubscription, CURLINFO_HTTP_CODE);
        curl_close($chSubscription);

        $subscriptionObj = json_decode($responseSubscription);
        if ($httpCodeSubscription >= 200 && $httpCodeSubscription < 300 && isset($subscriptionObj->id)) {
            return '
                <div style="text-align:center; margin-top:15px;">
                    <div class="alert alert-success" style="margin-bottom: 10px; text-align:left;">
                        <strong>Assinatura criada com sucesso no Asaas.</strong>
                        <br>
                        A cobrança recorrente foi configurada para o cliente e o ciclo foi definido com base na configuração do WHMCS.
                    </div>
                    <small>Processado via Asaas</small>
                </div>';
        }

        $errorMessage = isset($subscriptionObj->errors[0]->description) ? $subscriptionObj->errors[0]->description : 'Erro ao criar assinatura';
        logTransaction($params['name'], $responseSubscription, 'Erro Assinatura Asaas');
        return '<div class="alert alert-danger">Erro Asaas: ' . htmlspecialchars($errorMessage) . '</div>';
    }

    $label = $autoPix ? 'Pagar Fatura (Pix)' : 'Pagar Fatura (Pix/Boleto/Cartão)';

    $existingPayment = asaas_find_invoice_payment($apiUrl, $apiKey, $invoiceId);
    if ($existingPayment) {
        $existingStatus = strtoupper((string) ($existingPayment->status ?? ''));
        if (in_array($existingStatus, array('RECEIVED', 'CONFIRMED', 'RECEIVED_IN_CASH'), true)) {
            return '<div class="alert alert-success" style="text-align:center;">Pagamento desta fatura já recebido pelo Asaas.</div>';
        }

        $needsUpdate = (string) ($existingPayment->dueDate ?? '') !== $dueDate
            || abs((float) ($existingPayment->value ?? 0) - $amount) >= 0.01;
        if ($needsUpdate) {
            $updated = asaas_update_payment($apiUrl, $apiKey, (string) $existingPayment->id, array(
                'billingType' => (string) ($existingPayment->billingType ?? $billingType),
                'value' => $amount,
                'dueDate' => $dueDate,
            ));
            if ($updated) {
                $existingPayment = $updated;
            } else {
                logTransaction($params['name'], array('payment' => $existingPayment->id, 'dueDate' => $dueDate, 'value' => $amount), 'Erro ao atualizar vencimento Asaas');
            }
        }

        if (!empty($existingPayment->invoiceUrl)) {
            return asaas_payment_button((string) $existingPayment->invoiceUrl, $label);
        }
    }

    $paymentData = array(
        'billingType' => $billingType,
        'value' => $amount,
        'dueDate' => $dueDate,
        'description' => 'Fatura #' . $invoiceId,
        'externalReference' => $invoiceId,
        'postalService' => false,
        'customer' => $customerId,
    );

    $chPayment = curl_init();
    curl_setopt($chPayment, CURLOPT_URL, $apiUrl . '/payments');
    curl_setopt($chPayment, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chPayment, CURLOPT_POST, true);
    curl_setopt($chPayment, CURLOPT_POSTFIELDS, json_encode($paymentData));
    curl_setopt($chPayment, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json',
        'access_token: ' . $apiKey,
    ));

    $responsePayment = curl_exec($chPayment);
    $httpCodePayment = curl_getinfo($chPayment, CURLINFO_HTTP_CODE);
    curl_close($chPayment);

    $paymentObj = json_decode($responsePayment);

    if ($httpCodePayment >= 200 && $httpCodePayment < 300 && isset($paymentObj->invoiceUrl)) {
        return asaas_payment_button((string) $paymentObj->invoiceUrl, $label);
    }

    $errorMessage = isset($paymentObj->errors[0]->description) ? $paymentObj->errors[0]->description : 'Erro na API do Asaas';
    return '<div class="alert alert-danger">Erro Asaas: ' . htmlspecialchars($errorMessage) . '</div>';
}

?>
