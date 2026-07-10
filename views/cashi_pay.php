<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<body class="gateway-cashi-pay">
    <div class="container">
        <div class="col-md-6 col-md-offset-3 mtop30">
            <div class="mbot30 text-center cashi-logo-wrapper">
                <?php echo payment_gateway_logo(); ?>
            </div>
            <div class="panel_s cashi-pay-panel">
                <div class="panel-heading text-center">
                    <h4 class="panel-title tw-mb-1"><?php echo _l('cashi_pay_heading'); ?></h4>
                    <a href="<?php echo e($invoiceUrl); ?>" class="tw-text-neutral-500">
                        <?php echo format_invoice_number($invoice->id); ?>
                    </a>
                </div>
                <div class="panel-body text-center">
                    <div class="cashi-qr-wrapper">
                        <img src="<?php echo e($qrImageUrl); ?>" alt="Cashi QR" class="cashi-qr-image">
                    </div>

                    <div class="cashi-pay-details">
                        <div class="cashi-pay-details-row">
                            <span class="cashi-pay-details-label"><?php echo _l('cashi_pay_reference'); ?></span>
                            <span class="cashi-pay-details-value"><?php echo e($referenceNumber); ?></span>
                        </div>
                        <div class="cashi-pay-details-row">
                            <span class="cashi-pay-details-label"><?php echo _l('cashi_pay_amount'); ?></span>
                            <span class="cashi-pay-details-value cashi-pay-amount"><?php echo e(number_format((float) $amount, 2)) . ' ' . e($currency); ?></span>
                        </div>
                        <?php if (!empty($expiresAt)) { ?>
                        <div class="cashi-pay-details-row">
                            <span class="cashi-pay-details-label"><?php echo _l('cashi_pay_expires_at'); ?></span>
                            <span class="cashi-pay-details-value"><?php echo e(_dt(date('Y-m-d H:i:s', strtotime($expiresAt)))); ?></span>
                        </div>
                        <?php } ?>
                    </div>

                    <hr>

                    <div class="cashi-pay-instructions">
                        <strong class="cashi-pay-instructions-title"><?php echo _l('cashi_pay_instructions_title'); ?></strong>
                        <div class="cashi-pay-step">
                            <span class="cashi-pay-step-number">1</span>
                            <span class="cashi-pay-step-text"><?php echo _l('cashi_pay_instructions_step1'); ?></span>
                        </div>
                        <div class="cashi-pay-step">
                            <span class="cashi-pay-step-number">2</span>
                            <span class="cashi-pay-step-text"><?php echo _l('cashi_pay_instructions_step2'); ?></span>
                        </div>
                        <div class="cashi-pay-step">
                            <span class="cashi-pay-step-number">3</span>
                            <span class="cashi-pay-step-text"><?php echo _l('cashi_pay_instructions_step3'); ?></span>
                        </div>
                    </div>

                    <div id="cashi-pay-status" class="alert alert-info mtop15 tw-flex tw-items-center tw-justify-center tw-gap-2">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                        <span><?php echo _l('cashi_pay_waiting'); ?></span>
                    </div>
                </div>
                <div class="panel-footer text-center">
                    <a href="<?php echo e($invoiceUrl); ?>" class="btn btn-default">
                        <?php echo _l('cashi_pay_back_to_invoice'); ?>
                    </a>
                    <button type="button" id="cashi-check-now" class="btn btn-primary">
                        <?php echo _l('cashi_pay_check_now'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <style>
    .cashi-logo-wrapper img {
        height: 60px !important;
        width: auto !important;
    }
    .cashi-pay-panel {
        max-width: 420px;
        margin: 0 auto;
    }
    .cashi-qr-wrapper {
        display: flex;
        justify-content: center;
        margin-bottom: 20px;
    }
    .cashi-qr-image {
        width: 220px;
        height: 220px;
        padding: 12px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
    }
    .cashi-pay-details {
        margin: 0 auto 20px;
        max-width: 320px;
    }
    .cashi-pay-details-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid #f1f5f9;
    }
    .cashi-pay-details-row:last-child {
        border-bottom: 0;
    }
    .cashi-pay-details-label {
        font-weight: 600;
        color: #475569;
    }
    .cashi-pay-details-value {
        color: #0f172a;
    }
    .cashi-pay-amount {
        font-weight: 700;
        font-size: 16px;
    }
    .cashi-pay-instructions {
        max-width: 320px;
        margin: 0 auto;
        text-align: start;
    }
    .cashi-pay-instructions-title {
        display: block;
        margin-bottom: 14px;
        color: #0f172a;
    }
    .cashi-pay-step {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        margin-bottom: 14px;
    }
    .cashi-pay-step:last-child {
        margin-bottom: 0;
    }
    .cashi-pay-step-number {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #2563eb;
        color: #fff;
        font-size: 12px;
        font-weight: 700;
    }
    .cashi-pay-step-text {
        padding-top: 2px;
        color: #334155;
        line-height: 1.5;
    }
    </style>

    <?php echo payment_gateway_scripts(); ?>
    <script>
    (function() {
        var statusUrl = <?php echo json_encode($statusUrl); ?>;
        var pollIntervalMs = <?php echo (int) $pollInterval * 1000; ?>;
        var expiresAtMs = <?php echo json_encode(!empty($expiresAt) ? strtotime($expiresAt) * 1000 : null); ?>;
        var timer = null;

        function checkStatus() {
            $.getJSON(statusUrl).done(function(data) {
                if (data.redirect) {
                    clearInterval(timer);
                    window.location.href = data.redirect;
                }
            });
        }

        timer = setInterval(function() {
            // Once the payment request has expired there is nothing left to poll for - Cashi's
            // own status will eventually flip to EXPIRED and redirect, but there is no reason
            // for an abandoned tab to keep hitting our server (and Cashi's API) forever before
            // that happens.
            if (expiresAtMs && Date.now() > expiresAtMs) {
                clearInterval(timer);

                return;
            }

            checkStatus();
        }, pollIntervalMs);

        $('#cashi-check-now').on('click', function() {
            checkStatus();
        });
    })();
    </script>
</body>
