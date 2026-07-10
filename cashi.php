<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
Module Name: Cashi Gateway
Description: Accept invoice payments via CashiPay (QR-based mobile payments settled in SDG).
Version: 1.0.0
Requires at least: 2.3.*
*/

define('CASHI_MODULE_NAME', 'cashi');
define('CASHI_GATEWAY_ID', 'Cashi_gateway');

register_payment_gateway(CASHI_GATEWAY_ID, CASHI_MODULE_NAME);

register_language_files(CASHI_MODULE_NAME, [CASHI_MODULE_NAME]);
