<?php
/**
 * Módulo addon Asaas para emissão de notas fiscais em assinaturas WHMCS
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/functions.php';

function asaas_nf_MetaData()
{
    return array(
        'DisplayName' => 'Asaas NF',
        'Description' => 'Módulo de emissão fiscal para WHMCS com integração ao Asaas.',
        'Author' => 'Launcher Tech',
        'Version' => '1.0.0',
        'APIVersion' => '1.1',
        'Language' => 'english',
    );
}

function asaas_nf_config()
{
    return array(
        'name' => 'Asaas NF',
        'description' => 'Módulo de emissão fiscal para WHMCS com integração ao Asaas.',
        'author' => 'Launcher Tech',
        'version' => '1.0.0',
        'fields' => array(
            'apiKey' => array(
                'FriendlyName' => 'Chave de API',
                'Type' => 'password',
                'Size' => '50',
                'Description' => '<br><small style="color:#777;">Informe a API Key do Asaas para emissão fiscal.</small>',
            ),
            'sandbox' => array(
                'FriendlyName' => 'Modo Sandbox',
                'Type' => 'yesno',
                'Description' => '<br><small style="color:#777;">Ative para usar o ambiente de testes do Asaas.</small>',
            ),
            'emissionMode' => array(
                'FriendlyName' => 'Modo de emissão',
                'Type' => 'dropdown',
                'Options' => array(
                    'manual' => 'Manual',
                    'automatic' => 'Automático',
                ),
                'Default' => 'manual',
                'Description' => '<br><small style="color:#777;">Escolha se a nota fiscal será emitida manualmente ou automaticamente conforme o gatilho abaixo.</small>',
            ),
            'automaticTrigger' => array(
                'FriendlyName' => 'Gatilho automático',
                'Type' => 'dropdown',
                'Options' => array(
                    'manual' => 'Nunca (manual apenas)',
                    'invoice_created' => 'Na geração da cobrança',
                    'due_date' => 'No dia do vencimento',
                    'payment_received' => 'Quando houver pagamento',
                ),
                'Default' => 'manual',
                'Description' => '<br><small style="color:#777;">Quando o modo automático estiver ativo, este gatilho define o momento da emissão da nota fiscal.</small>',
            ),
            'invoiceEndpoint' => array(
                'FriendlyName' => 'Endpoint de emissão',
                'Type' => 'text',
                'Size' => '40',
                'Default' => '/invoices',
                'Description' => '<br><small style="color:#777;">Ex.: /invoices. Ajuste caso seu ambiente do Asaas use uma rota fiscal específica.</small>',
            ),
            'serviceMode' => array(
                'FriendlyName' => 'Serviço usado na NF',
                'Type' => 'dropdown',
                'Options' => array(
                    'default' => 'Sempre o serviço padrão',
                    'per_product' => 'Um serviço por produto do WHMCS',
                ),
                'Default' => 'default',
                'Description' => '<br><small style="color:#777;">"Serviço padrão" usa sempre o mesmo serviço municipal no Asaas. "Por produto" usa o nome do produto WHMCS como nome do serviço (um único cadastro por produto, reaproveitado nas próximas notas). O detalhe de cada fatura vai apenas na descrição da nota, nunca no nome do serviço.</small>',
            ),
            'municipalServiceId' => array(
                'FriendlyName' => 'ID do serviço municipal (opcional)',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '',
                'Description' => '<br><small style="color:#777;">ID retornado por GET /v3/fiscalInfo/services, quando a prefeitura fornece a lista de serviços. Se preenchido, é usado no modo "serviço padrão" e o código abaixo não é enviado.</small>',
            ),
            'municipalServiceName' => array(
                'FriendlyName' => 'Nome do serviço padrão',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'Hospedagem de dados e serviços de TI',
                'Description' => '<br><small style="color:#777;">Nome fixo do serviço municipal no Asaas (municipalServiceName). Use exatamente o nome do serviço já cadastrado na sua conta para que ele seja reaproveitado.</small>',
            ),
            'serviceCode' => array(
                'FriendlyName' => 'Código do serviço municipal',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '',
                'Description' => '<br><small style="color:#777;">Enviado como municipalServiceCode quando não há ID de serviço. Deixe vazio para usar o código de tributação nacional abaixo (contas no Portal Nacional).</small>',
            ),
            'nationalServiceCode' => array(
                'FriendlyName' => 'Código de tributação nacional',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '01.03.02',
                'Description' => '<br><small style="color:#777;">Ex.: 01.03.02 - armazenamento ou hospedagem de dados (formato com pontos, como no Asaas). Usado como municipalServiceCode quando o campo acima estiver vazio.</small>',
            ),
            'serviceProvisionCityDefaultType' => array(
                'FriendlyName' => 'Município padrão da prestação',
                'Type' => 'dropdown',
                'Options' => array(
                    'MY_ACCOUNT_CITY' => 'Município da conta',
                    'CUSTOMER_CITY' => 'Município do cliente',
                    'CUSTOMER_CITY_WHEN_ISS_IS_RETAINED' => 'Município do cliente quando ISS retido',
                ),
                'Default' => 'MY_ACCOUNT_CITY',
                'Description' => '<br><small style="color:#777;">Defina a cidade usada por padrão nas NFS-e emitidas conforme a exigência do município.</small>',
            ),
            'defaultDescription' => array(
                'FriendlyName' => 'Descrição padrão',
                'Type' => 'text',
                'Size' => '60',
                'Default' => 'Serviço de assinatura WHMCS',
                'Description' => '<br><small style="color:#777;">Usada na descrição da nota (serviceDescription) quando a fatura não tiver itens com descrição.</small>',
            ),
            'nbsCode' => array(
                'FriendlyName' => 'Código NBS (Reforma)',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '1.1506.21.00',
                'Description' => '<br><small style="color:#777;">Enviado em taxes.nbsCode. Ex.: 1.1506.21.00. Deixe vazio para não enviar.</small>',
            ),
            'taxSituation' => array(
                'FriendlyName' => 'Situação tributária IBS/CBS (CST)',
                'Type' => 'text',
                'Size' => '10',
                'Default' => '000',
                'Description' => '<br><small style="color:#777;">Enviado em taxes.taxSituationCode. Ex.: 000 - tributação integral. Deixe vazio para não enviar.</small>',
            ),
            'cClassTrib' => array(
                'FriendlyName' => 'Classificação tributária (cClassTrib)',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '000001',
                'Description' => '<br><small style="color:#777;">Enviado em taxes.taxClassificationCode; deve ser compatível com o CST. Deixe vazio para não enviar.</small>',
            ),
            'operationIndicator' => array(
                'FriendlyName' => 'Indicador da operação',
                'Type' => 'text',
                'Size' => '20',
                'Default' => '010103',
                'Description' => '<br><small style="color:#777;">Enviado em taxes.operationIndicatorCode. Deixe vazio para não enviar.</small>',
            ),
            'taxes' => array(
                'FriendlyName' => 'Tributos padrão',
                'Type' => 'text',
                'Size' => '80',
                'Default' => '{"iss":5,"pis":0,"cofins":0,"csll":0,"inss":0,"ir":0,"retainIss":false,"pisCofinsTaxStatus":"STANDARD_TAXABLE_OPERATION","operationPis":0.65,"operationCofins":3}',
                'Description' => '<br><small style="color:#777;">JSON dos tributos. pis/cofins/csll/inss/ir = alíquotas RETIDAS pelo tomador; operationPis/operationCofins = PIS/COFINS próprios da operação; pisCofinsTaxStatus = situação do PIS/COFINS (ex.: STANDARD_TAXABLE_OPERATION).</small>',
            ),
        ),
    );
}

function asaas_nf_activate()
{
    try {
        Capsule::table('tbladdonmodules')
            ->where('module', 'asaas_nf')
            ->where('setting', 'version')
            ->updateOrInsert(
                ['module' => 'asaas_nf', 'setting' => 'version'],
                ['value' => '1.0.0']
            );

        Capsule::table('tbladdonmodules')
            ->where('module', 'asaas_nf')
            ->where('setting', 'version')
            ->whereNull('value')
            ->update(['value' => '1.0.0']);
    } catch (\Exception $e) {
        // Ignora falha de manutenção do registro de versão; o módulo continua funcionando.
    }

    return array(
        'status' => 'success',
        'description' => 'Módulo Asaas NF ativado com sucesso.',
    );
}

function asaas_nf_deactivate()
{
    return array(
        'status' => 'success',
        'description' => 'Módulo Asaas NF desativado com sucesso.',
    );
}

function asaas_nf_output($vars)
{
    $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
    $filterStatus = isset($_POST['status_filter']) ? (string) $_POST['status_filter'] : 'all';
    $viewMode = isset($_POST['view_mode']) ? (string) $_POST['view_mode'] : 'pending';
    $limit = isset($_POST['limit']) ? max(1, min(100, (int) $_POST['limit'])) : 25;
    $result = null;

    if ($action === 'emit_nf') {
        $invoiceId = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
        $result = asaas_nf_emit_invoice($invoiceId, $vars);
    } elseif ($action === 'sync_nf_status') {
        $invoiceId = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
        $result = asaas_nf_sync_invoice_status($invoiceId, $vars);
    } elseif ($action === 'sync_nf_pending') {
        $results = asaas_nf_sync_pending_statuses($vars);
        $result = empty($results)
            ? array('status' => 'success', 'message' => 'Nenhuma NF aguardando atualização de status.')
            : array('status' => 'success', 'message' => 'Status consultado no Asaas para ' . count($results) . ' fatura(s).', 'details' => $results);
    } elseif ($action === 'reset_nf_state') {
        $invoiceId = isset($_POST['invoice_id']) ? (int) $_POST['invoice_id'] : 0;
        $result = asaas_nf_reset_nf_state($invoiceId);
    } elseif ($action === 'emit_nf_selected') {
        $selectedIds = isset($_POST['invoice_ids']) && is_array($_POST['invoice_ids']) ? array_map('intval', $_POST['invoice_ids']) : array();
        $selectedIds = array_values(array_unique(array_filter($selectedIds, function ($id) {
            return $id > 0;
        })));

        if (empty($selectedIds)) {
            $result = array('status' => 'error', 'message' => 'Nenhuma fatura foi selecionada para emissão.');
        } else {
            $results = array();
            foreach ($selectedIds as $invoiceId) {
                $results[] = asaas_nf_emit_invoice($invoiceId, $vars);
            }

            $successCount = 0;
            foreach ($results as $entry) {
                if (isset($entry['status']) && $entry['status'] === 'success') {
                    $successCount++;
                }
            }

            $result = array(
                'status' => $successCount === count($results) ? 'success' : 'warning',
                'message' => 'Processadas ' . count($results) . ' faturas. ' . $successCount . ' emitidas com sucesso.',
                'details' => $results,
            );
        }
    } elseif ($action === 'emit_nf_status' && !empty($_POST['confirm_batch'])) {
        $query = Capsule::table('tblinvoices as inv')
            ->leftJoin('tblclients as c', 'inv.userid', '=', 'c.id')
            ->select('inv.id')
            ->whereNotIn('inv.status', array('Cancelled', 'Draft', 'Refunded', 'Collections'));

        if ($filterStatus !== 'all') {
            $query->where('inv.status', $filterStatus);
        }

        if ($viewMode === 'pending') {
            $query->whereRaw('LOWER(COALESCE(inv.notes, "")) NOT LIKE ?', array('%asaas nf%'));
        } elseif ($viewMode === 'emitted') {
            $query->whereRaw('LOWER(COALESCE(inv.notes, "")) LIKE ?', array('%asaas nf%'));
        }

        $invoiceIds = $query->orderByDesc('inv.id')->limit($limit)->pluck('inv.id')->toArray();

        if (empty($invoiceIds)) {
            $result = array('status' => 'error', 'message' => 'Nenhuma fatura encontrada para o filtro selecionado.');
        } else {
            $results = array();
            foreach ($invoiceIds as $invoiceId) {
                if (asaas_nf_invoice_has_nf_record((int) $invoiceId)) {
                    $results[] = array('status' => 'warning', 'message' => 'Fatura #' . (int) $invoiceId . ' já possui NF registrada.');
                    continue;
                }
                $results[] = asaas_nf_emit_invoice((int) $invoiceId, $vars);
            }

            $successCount = 0;
            foreach ($results as $entry) {
                if (isset($entry['status']) && $entry['status'] === 'success') {
                    $successCount++;
                }
            }

            $result = array(
                'status' => $successCount === count($results) ? 'success' : 'warning',
                'message' => 'Lote processado: ' . count($results) . ' faturas. ' . $successCount . ' emitidas com sucesso.',
                'details' => $results,
            );
        }
    }


    $query = Capsule::table('tblinvoices as inv')
        ->leftJoin('tblclients as c', 'inv.userid', '=', 'c.id')
        ->select('inv.id', 'inv.total', 'inv.status', 'inv.notes', 'inv.date', 'inv.duedate', 'c.firstname', 'c.lastname', 'c.email');

    if ($filterStatus !== 'all') {
        $query->where('inv.status', $filterStatus);
    }

    if ($viewMode === 'pending') {
        $query->whereRaw('LOWER(COALESCE(inv.notes, "")) NOT LIKE ?', array('%asaas nf%'));
    } elseif ($viewMode === 'emitted') {
        $query->whereRaw('LOWER(COALESCE(inv.notes, "")) LIKE ?', array('%asaas nf%'));
    }

    $invoices = $query->orderByDesc('inv.id')->limit($limit)->get();

    // Totais das faturas elegíveis (mesmo recorte da emissão em lote), independentes do filtro.
    $countBase = function () {
        return Capsule::table('tblinvoices')->whereNotIn('status', array('Cancelled', 'Draft', 'Refunded', 'Collections'));
    };
    $stats = array(
        'pending' => $countBase()->whereRaw('LOWER(COALESCE(notes, "")) NOT LIKE ?', array('%asaas nf%'))->count(),
        'emitted' => $countBase()->whereRaw('LOWER(COALESCE(notes, "")) LIKE ?', array('%asaas nf%'))->count(),
        // "erro" também cobre INVOICE_ERROR, como em asaas_nf_get_status_badge().
        'errors' => $countBase()->whereRaw('LOWER(COALESCE(notes, "")) LIKE ?', array('%asaas nf%'))
            ->whereRaw('LOWER(notes) LIKE ?', array('%erro%'))->count(),
    );

    $emissionMode = (string) ($vars['emissionMode'] ?? 'manual');
    $triggerLabels = array(
        'manual' => 'Nunca (manual apenas)',
        'invoice_created' => 'Na geração da cobrança',
        'due_date' => 'No dia do vencimento',
        'payment_received' => 'Quando houver pagamento',
    );
    $trigger = (string) ($vars['automaticTrigger'] ?? 'manual');
    $isAutomatic = $emissionMode === 'automatic' && $trigger !== 'manual';
    $isSandbox = !empty($vars['sandbox']) && $vars['sandbox'] !== 'off';

    $statusLabels = array(
        'Draft' => array('Rascunho', 'default'),
        'Unpaid' => array('Em aberto', 'warning'),
        'Paid' => array('Paga', 'success'),
        'Overdue' => array('Vencida', 'danger'),
        'Cancelled' => array('Cancelada', 'default'),
        'Refunded' => array('Reembolsada', 'info'),
        'Collections' => array('Cobrança', 'default'),
    );
    $formatDate = function ($date) {
        $date = (string) $date;
        if ($date === '' || strpos($date, '0000-00-00') === 0) {
            return '—';
        }
        $time = strtotime($date);
        return $time ? date('d/m/Y', $time) : $date;
    };

    echo '<style>
.anf { max-width: 1280px; color: #333; }
.anf-header { display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
.anf-header h2 { margin: 0 0 4px; font-size: 22px; font-weight: 600; }
.anf-header p { margin: 0; color: #6b7280; font-size: 13px; }
.anf-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.anf-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
.anf-chip .anf-dot { width: 7px; height: 7px; border-radius: 50%; background: #94a3b8; }
.anf-chip.is-on .anf-dot { background: #16a34a; }
.anf-chip.is-sandbox { background: #fff7ed; color: #9a3412; border-color: #fed7aa; }
.anf-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 18px; }
.anf-stat { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 14px 16px; border-left: 4px solid #cbd5e1; }
.anf-stat .anf-stat-label { font-size: 12px; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; font-weight: 600; }
.anf-stat .anf-stat-value { font-size: 26px; font-weight: 700; line-height: 1.2; margin-top: 2px; }
.anf-stat.is-pending { border-left-color: #f59e0b; }
.anf-stat.is-emitted { border-left-color: #16a34a; }
.anf-stat.is-error { border-left-color: #dc2626; }
.anf-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 18px; }
.anf-filters { display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px; padding: 14px 16px; }
.anf-filters .form-group { margin: 0; flex: 0 1 220px; min-width: 160px; }
.anf-filters .form-group.is-narrow { flex: 0 0 90px; min-width: 0; }
.anf-filters .form-control { width: 100%; }
.anf-filters label { font-size: 12px; font-weight: 600; color: #4b5563; margin-bottom: 4px; }
.anf-filters .anf-filter-actions { flex: 0 0 auto; }
.anf-toolbar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; }
.anf-toolbar .anf-count { color: #6b7280; font-size: 13px; }
.anf-toolbar .anf-toolbar-actions { display: flex; flex-wrap: wrap; gap: 8px; }
.anf-table-wrap { overflow-x: auto; }
.anf-table { margin: 0; }
.anf-table > thead > tr > th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; border-bottom: 1px solid #e5e7eb; background: #f9fafb; white-space: nowrap; padding: 10px 12px; }
.anf-table > tbody > tr > td { vertical-align: middle; padding: 10px 12px; border-top: 1px solid #f1f5f9; }
.anf-table > tbody > tr:hover { background: #f8fafc; }
.anf-table > tbody > tr.is-selected { background: #eff6ff; }
.anf-table .anf-client-name { font-weight: 600; }
.anf-table .anf-client-email { color: #6b7280; font-size: 12px; }
.anf-table .text-right { text-align: right; }
.anf-table .anf-actions { white-space: nowrap; text-align: right; }
.anf-table .anf-actions form { display: inline-block; margin: 0 0 0 4px; }
.anf-table .label { font-size: 11px; padding: 4px 7px; }
.anf-empty { text-align: center; padding: 40px 16px !important; color: #6b7280; }
.anf-help { color: #6b7280; font-size: 12px; margin: -6px 0 18px; }
</style>';

    echo '<div class="anf">';

    echo '<div class="anf-header">';
    echo '<div><h2>Notas fiscais das faturas</h2>';
    echo '<p>Emita a NFS-e das faturas do WHMCS pelo Asaas. Com cobrança do gateway, a nota fica vinculada a ela; sem cobrança, sai avulsa para o cliente.</p></div>';
    echo '<div class="anf-chips">';
    echo '<span class="anf-chip' . ($isAutomatic ? ' is-on' : '') . '"><span class="anf-dot"></span>'
        . ($isAutomatic ? 'Automático: ' . htmlspecialchars($triggerLabels[$trigger] ?? $trigger) : 'Emissão manual') . '</span>';
    if ($isSandbox) {
        echo '<span class="anf-chip is-sandbox">Sandbox</span>';
    }
    echo '</div>';
    echo '</div>';

    if ($result) {
        $alertClass = $result['status'] === 'success' ? 'success' : ($result['status'] === 'warning' ? 'warning' : 'danger');
        echo '<div class="alert alert-' . $alertClass . '">';
        echo htmlspecialchars($result['message']);
        if (!empty($result['details']) && is_array($result['details'])) {
            echo '<ul style="margin:8px 0 0;">';
            foreach ($result['details'] as $detail) {
                echo '<li>' . htmlspecialchars((string) ($detail['message'] ?? '')) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    echo '<div class="anf-stats">';
    echo '<div class="anf-stat is-pending"><div class="anf-stat-label">Sem NF</div><div class="anf-stat-value">' . (int) $stats['pending'] . '</div></div>';
    echo '<div class="anf-stat is-emitted"><div class="anf-stat-label">Com NF registrada</div><div class="anf-stat-value">' . (int) $stats['emitted'] . '</div></div>';
    echo '<div class="anf-stat is-error"><div class="anf-stat-label">Com erro na NF</div><div class="anf-stat-value">' . (int) $stats['errors'] . '</div></div>';
    echo '</div>';
    echo '<p class="anf-help">Totais de faturas ativas (sem canceladas, rascunhos, reembolsadas e em cobrança).</p>';

    echo '<div class="anf-card">';
    echo '<form method="post" action="" id="anf_filter_form" class="anf-filters">';
    echo '<div class="form-group">';
    echo '<label for="status_filter">Status da fatura</label>';
    echo '<select name="status_filter" class="form-control" id="status_filter">';
    $statusOptions = array('all' => 'Todos');
    foreach (array('Draft', 'Unpaid', 'Paid', 'Overdue', 'Cancelled', 'Refunded') as $value) {
        $statusOptions[$value] = $statusLabels[$value][0];
    }
    foreach ($statusOptions as $value => $label) {
        $selected = ($filterStatus === $value) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="form-group">';
    echo '<label for="view_mode">Nota fiscal</label>';
    echo '<select name="view_mode" class="form-control" id="view_mode">';
    $viewOptions = array('pending' => 'Sem NF (pendentes)', 'emitted' => 'Com NF registrada', 'all' => 'Todas');
    foreach ($viewOptions as $value => $label) {
        $selected = ($viewMode === $value) ? ' selected' : '';
        echo '<option value="' . htmlspecialchars($value) . '"' . $selected . '>' . htmlspecialchars($label) . '</option>';
    }
    echo '</select>';
    echo '</div>';
    echo '<div class="form-group is-narrow">';
    echo '<label for="limit">Exibir</label>';
    echo '<input type="number" name="limit" id="limit" value="' . (int) $limit . '" min="1" max="100" class="form-control">';
    echo '</div>';
    echo '<div class="anf-filter-actions">';
    echo '<input type="hidden" name="confirm_batch" value="1">';
    echo '<button type="submit" name="action" value="filter" class="btn btn-primary">Aplicar filtro</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    $invoiceCount = count($invoices);

    echo '<div class="anf-card">';
    echo '<div class="anf-toolbar">';
    echo '<span class="anf-count">' . $invoiceCount . ' fatura' . ($invoiceCount === 1 ? '' : 's') . ' listada' . ($invoiceCount === 1 ? '' : 's') . '</span>';
    echo '<div class="anf-toolbar-actions">';
    echo '<form method="post" action="" style="display:inline;margin:0;"><button type="submit" name="action" value="sync_nf_pending" class="btn btn-default btn-sm" title="Consulta no Asaas as NFs agendadas/sincronizadas e atualiza o status, caso o webhook não tenha chegado.">Sincronizar status das NFs</button></form>';
    echo '<button type="submit" form="asaas_nf_selected_form" id="anf_emit_selected" class="btn btn-success btn-sm" disabled onclick="return confirm(\'Emitir NF das faturas selecionadas?\');">Emitir NF das selecionadas (<span id="anf_selected_count">0</span>)</button>';
    if ($invoiceCount > 0) {
        echo '<button type="submit" form="anf_filter_form" name="action" value="emit_nf_status" class="btn btn-default btn-sm" style="color:#b91c1c;" onclick="return confirm(\'Emitir NF para TODAS as faturas deste filtro (até \' + document.getElementById(\'limit\').value + \')? Faturas que já têm NF registrada são ignoradas.\');">Emitir NF de todas do filtro</button>';
    }
    echo '</div>';
    echo '</div>';

    echo '<div class="anf-table-wrap">';
    echo '<table class="table anf-table">';
    echo '<thead><tr><th style="width:36px;"><input type="checkbox" id="select_all_invoices" title="Selecionar todas" /></th><th>Fatura</th><th>Cliente</th><th>Vencimento</th><th class="text-right">Valor</th><th>Status</th><th>NF</th><th class="text-right">Ações</th></tr></thead><tbody>';

    if ($invoiceCount === 0) {
        echo '<tr><td colspan="8" class="anf-empty">Nenhuma fatura encontrada para este filtro.</td></tr>';
    }

    foreach ($invoices as $invoice) {
        $invoiceId = (int) $invoice->id;
        $notes = trim((string) ($invoice->notes ?? ''));
        $nfStatus = asaas_nf_get_status_badge($notes);
        $hasNfRecord = stripos($notes, 'asaas nf') !== false;
        $status = (string) $invoice->status;
        $statusLabel = $statusLabels[$status] ?? array($status, 'default');
        $clientName = trim((string) ($invoice->firstname ?? '') . ' ' . (string) ($invoice->lastname ?? ''));
        $formattedAmount = function_exists('formatCurrency') ? formatCurrency($invoice->total) : number_format((float) $invoice->total, 2, ',', '.');

        echo '<tr>';
        echo '<td><input type="checkbox" name="invoice_ids[]" value="' . $invoiceId . '" class="invoice-checkbox" form="asaas_nf_selected_form" /></td>';
        echo '<td><a href="invoices.php?action=edit&amp;id=' . $invoiceId . '" target="_blank"><strong>#' . $invoiceId . '</strong></a></td>';
        echo '<td><div class="anf-client-name">' . htmlspecialchars($clientName !== '' ? $clientName : '—') . '</div>';
        echo '<div class="anf-client-email">' . htmlspecialchars((string) ($invoice->email ?? '')) . '</div></td>';
        echo '<td>' . htmlspecialchars($formatDate($invoice->duedate)) . '</td>';
        echo '<td class="text-right">' . htmlspecialchars((string) $formattedAmount) . '</td>';
        echo '<td><span class="label label-' . $statusLabel[1] . '">' . htmlspecialchars($statusLabel[0]) . '</span></td>';
        echo '<td>' . $nfStatus . '</td>';
        echo '<td class="anf-actions">';
        echo '<form method="post" action="">';
        echo '<input type="hidden" name="action" value="emit_nf">';
        echo '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">';
        echo '<button type="submit" class="btn btn-primary btn-xs" onclick="return confirm(\'Emitir NF da fatura #' . $invoiceId . '?\');">Emitir NF</button>';
        echo '</form>';
        if ($hasNfRecord) {
            echo '<form method="post" action="">';
            echo '<input type="hidden" name="action" value="sync_nf_status">';
            echo '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">';
            echo '<button type="submit" class="btn btn-default btn-xs" title="Consulta o status atual da NF no Asaas.">Atualizar status</button>';
            echo '</form>';
            echo '<form method="post" action="">';
            echo '<input type="hidden" name="action" value="reset_nf_state">';
            echo '<input type="hidden" name="invoice_id" value="' . $invoiceId . '">';
            echo '<button type="submit" class="btn btn-default btn-xs" title="Limpa o registro da NF no WHMCS. A nota no Asaas não é cancelada." onclick="return confirm(\'Resetar o estado da NF apenas no WHMCS? A nota no Asaas não é cancelada.\');">Resetar</button>';
            echo '</form>';
        }
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
    echo '</div>';

    echo '<form method="post" action="" id="asaas_nf_selected_form">';
    echo '<input type="hidden" name="action" value="emit_nf_selected">';
    echo '</form>';

    echo '<script type="text/javascript">';
    echo 'document.addEventListener("DOMContentLoaded", function () {';
    echo '  var selectAll = document.getElementById("select_all_invoices");';
    echo '  var boxes = document.querySelectorAll(".invoice-checkbox");';
    echo '  var button = document.getElementById("anf_emit_selected");';
    echo '  var counter = document.getElementById("anf_selected_count");';
    echo '  function refresh() {';
    echo '    var total = 0;';
    echo '    boxes.forEach(function (box) {';
    echo '      if (box.checked) { total++; }';
    echo '      var row = box.closest("tr"); if (row) { row.classList.toggle("is-selected", box.checked); }';
    echo '    });';
    echo '    if (counter) { counter.textContent = total; }';
    echo '    if (button) { button.disabled = total === 0; }';
    echo '    if (selectAll) { selectAll.checked = total > 0 && total === boxes.length; selectAll.indeterminate = total > 0 && total < boxes.length; }';
    echo '  }';
    echo '  boxes.forEach(function (box) { box.addEventListener("change", refresh); });';
    echo '  if (selectAll) {';
    echo '    selectAll.addEventListener("change", function () {';
    echo '      boxes.forEach(function (box) { box.checked = selectAll.checked; });';
    echo '      refresh();';
    echo '    });';
    echo '  }';
    echo '  refresh();';
    echo '});';
    echo '</script>';

    echo '</div>';
}
