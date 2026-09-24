<?php
/**
 * Pix Automático do gateway Asaas.
 * Cria, no cron diário do WHMCS, as cobranças vinculadas à autorização do cliente para as
 * faturas que entram na janela de dias úteis exigida pelo Asaas antes do vencimento.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook('DailyCronJob', 1, function ($vars) {
    if (!function_exists('getGatewayVariables')) {
        require_once ROOTDIR . '/includes/gatewayfunctions.php';
    }

    $params = getGatewayVariables('asaas');
    if (empty($params['type']) || empty($params['pixAutomatico'])) {
        return;
    }

    if (!function_exists('asaas_pixauto_cron')) {
        require_once ROOTDIR . '/modules/gateways/asaas.php';
    }

    try {
        asaas_pixauto_cron($params);
    } catch (\Exception $e) {
        logTransaction('asaas', array('error' => $e->getMessage()), 'Erro Cron Pix Automático');
    }
});
