<?php
/**
 * Hooks de emissão automática do addon Asaas NF.
 * O WHMCS carrega este arquivo automaticamente para addons ativos.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/functions.php';

add_hook('InvoiceCreated', 1, 'asaas_nf_hook_invoice_created');
add_hook('DailyCronJob', 1, 'asaas_nf_hook_daily_due_date');
add_hook('DailyCronJob', 2, 'asaas_nf_hook_daily_sync_status');
add_hook('InvoicePaid', 1, 'asaas_nf_hook_invoice_paid');
