<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Cashi_gateway extends App_gateway
{
    public bool $processingFees = false;

    /**
     * Whether the per-currency rate fields have already been appended to $this->settings.
     * Building them touches the database and the language library, neither of which is
     * guaranteed to be ready yet while this class is being autoloaded as a CI library -
     * so it is deferred to the first real access instead of happening in __construct().
     *
     * @var boolean
     */
    private $dynamicSettingsBuilt = false;

    public function __construct()
    {
        /**
         * Call App_gateway __construct function
         */
        parent::__construct();

        /**
         * REQUIRED
         * Gateway unique id
         * The ID must be alpha/alphanumeric
         */
        $this->setId('cashi');

        /**
         * REQUIRED
         * Gateway name
         */
        $this->setName('Cashi');

        /**
         * Add gateway settings
         *
         * Cashi always settles in SDG, so instead of restricting the gateway to a single
         * invoice currency, every currency configured in the system gets its own
         * "exchange rate to SDG" field appended lazily in buildDynamicSettings(), and the
         * gateway is allowed for all of them via the "currencies" field.
         */
        $this->setSettings([
            [
                'name'          => 'base_url',
                'label'         => 'settings_paymentmethod_cashi_base_url',
                'default_value' => 'https://prod-cashi-services.alsoug.com/cashipay/api/v1',
            ],
            [
                'name'      => 'api_key',
                'encrypted' => true,
                'label'     => 'settings_paymentmethod_cashi_api_key',
            ],
            [
                'name'          => 'description_dashboard',
                'label'         => 'settings_paymentmethod_description',
                'type'          => 'textarea',
                'default_value' => 'Payment for Invoice {invoice_number}',
            ],
            [
                'name'          => 'poll_interval',
                'label'         => 'settings_paymentmethod_cashi_poll_interval',
                'default_value' => 5,
            ],
        ]);
    }

    /**
     * How often (in seconds) the pay page should poll Cashi for the payment status
     *
     * @return int
     */
    public function getPollInterval()
    {
        $value = (int) $this->getSetting('poll_interval');

        return $value > 0 ? $value : 5;
    }

    /**
     * Append the per-currency exchange rate fields and the "currencies" field directly onto
     * the already-built settings array - does not go through setSettings() again, since that
     * would re-prepend/re-append the active/label/default_selected boilerplate a second time
     *
     * @return void
     */
    private function buildDynamicSettings()
    {
        if ($this->dynamicSettingsBuilt) {
            return;
        }

        $this->dynamicSettingsBuilt = true;

        $currencies = $this->getConvertibleCurrencies();

        $extra = [];

        foreach ($currencies as $currency) {
            // Keyed by the currency's id (always unique) rather than its lowercased name -
            // two currencies differing only by case (e.g. "usd"/"USD", which Perfex's schema
            // does not prevent) would otherwise silently share one rate setting and only one
            // of them would get a visible input field on the settings page.
            $extra[] = [
                'name'          => 'rate_id_' . (int) $currency['id'],
                'label'         => _l('settings_paymentmethod_cashi_rate', [$currency['name']]),
                'default_value' => '',
            ];
        }

        $extra[] = [
            'name'          => 'currencies',
            'label'         => 'currency',
            'default_value' => implode(',', array_map(function ($currency) {
                return $currency['name'];
            }, $currencies)) . ',SDG',
        ];

        $this->settings = array_merge($this->settings, $extra);
    }

    /**
     * All currencies configured in the system except SDG itself (nothing to convert there)
     *
     * @return array
     */
    private function getConvertibleCurrencies()
    {
        $this->ci->load->model('currencies_model');

        return array_filter($this->ci->currencies_model->get(), function ($currency) {
            return mb_strtoupper($currency['name']) !== 'SDG';
        });
    }

    /**
     * @inheritDoc
     */
    public function initMode($modes)
    {
        $this->buildDynamicSettings();

        return parent::initMode($modes);
    }

    /**
     * @inheritDoc
     */
    public function getSettings($formatted = true)
    {
        $this->buildDynamicSettings();

        return parent::getSettings($formatted);
    }

    /**
     * Exchange rate configured to convert the given currency into SDG
     *
     * @param  string $currencyName
     *
     * @return float|null Null when the currency is already SDG or no rate is configured
     */
    public function getRateToSdg($currencyName)
    {
        if (mb_strtoupper($currencyName) === 'SDG') {
            return 1.0;
        }

        $this->ci->load->model('currencies_model');

        $currency = current(array_filter($this->ci->currencies_model->get(), function ($row) use ($currencyName) {
            return mb_strtoupper($row['name']) === mb_strtoupper($currencyName);
        }));

        if (!$currency) {
            return null;
        }

        $rate = $this->getSetting('rate_id_' . (int) $currency['id']);

        // A rate of 0 (fat-fingered or left as a literal "0") would send Cashi a payment
        // request for 0.00 while the invoice still gets marked paid for its real amount once
        // that request completes - treat it the same as "not configured" rather than a valid rate.
        return ($rate !== '' && (float) $rate > 0) ? (float) $rate : null;
    }

    /**
     * Base API url, no trailing slash
     *
     * @return string
     */
    private function baseUrl()
    {
        return rtrim($this->getSetting('base_url'), '/');
    }

    /**
     * Perform an authenticated request against the CashiPay API
     *
     * @param  string $method HTTP method
     * @param  string $path   Path relative to the base url, no leading slash
     * @param  array  $body   Request body, only used for POST/PUT
     *
     * @return array|null Decoded JSON response, null on failure
     */
    private function request($method, $path, $body = null)
    {
        $url = $this->baseUrl() . '/' . ltrim($path, '/');

        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->decryptSetting('api_key'),
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            // Cashi's edge (AWS ALB/WAF) returns a bare 403 for requests with no
            // User-Agent header - PHP's cURL sends none by default, unlike the cli tool.
            CURLOPT_USERAGENT      => 'Perfex-CRM-Cashi-Gateway/1.0',
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $response = curl_exec($ch);
        $status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            log_activity('Cashi API request failed. Error: ' . $error);

            return null;
        }

        $decoded = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            log_activity('Cashi API request failed. Status: ' . $status . '. Body: ' . $response);

            return null;
        }

        return $decoded;
    }

    /**
     * Map a raw Cashi status string to a boolean paid/not-paid state
     *
     * @param  string $status
     *
     * @return string|null 'paid', 'failed', 'expired' or null when still pending/unknown
     */
    public function mapStatus($status)
    {
        switch (strtoupper((string) $status)) {
            case 'COMPLETED':
            case 'PAID':
            case 'SUCCESS':
            case 'APPROVED':
                return 'paid';
            case 'FAILED':
            case 'REJECTED':
            case 'CANCELLED':
                return 'failed';
            case 'EXPIRED':
                return 'expired';
            default:
                return null;
        }
    }

    /**
     * Create a payment request on the Cashi side
     *
     * @param  array $payload
     *
     * @return array|null
     */
    public function createPaymentRequest($payload)
    {
        return $this->request('POST', 'payment-requests', $payload);
    }

    /**
     * Fetch the current status of a payment request
     *
     * @param  string $referenceNumber
     *
     * @return array|null
     */
    public function getPaymentStatus($referenceNumber)
    {
        return $this->request('GET', 'payment-requests/' . rawurlencode($referenceNumber));
    }

    /**
     * Ensure the table used to persist Cashi payment request data (namely the QR code, which
     * Cashi's API only returns once, at creation time) exists
     *
     * @return void
     */
    private function ensureStorageTable()
    {
        if (!$this->ci->db->table_exists(db_prefix() . 'cashi_payment_requests')) {
            // IF NOT EXISTS guards against two concurrent first-ever requests both racing to
            // create this table (e.g. two customers hitting the pay page moments after install).
            $this->ci->db->query('CREATE TABLE IF NOT EXISTS `' . db_prefix() . "cashi_payment_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `invoiceid` int(11) NOT NULL,
  `reference_number` varchar(50) NOT NULL,
  `qr_data_url` longtext,
  `qr_content` varchar(255) DEFAULT NULL,
  `amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `currency` varchar(10) NOT NULL DEFAULT 'SDG',
  `expires_at` datetime DEFAULT NULL,
  `payment_recorded` tinyint(1) NOT NULL DEFAULT '0',
  `datecreated` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `invoiceid` (`invoiceid`),
  UNIQUE KEY `reference_number` (`reference_number`)
) ENGINE=InnoDB DEFAULT CHARSET=" . $this->ci->db->char_set . ';');

            return;
        }

        // Added after the table's initial release - guards addPayment() against being called
        // twice for the same reference number when the webhook and the customer's own
        // status-poll/return-url both resolve to "paid" at nearly the same time.
        if (!$this->ci->db->field_exists('payment_recorded', db_prefix() . 'cashi_payment_requests')) {
            $this->ci->db->query('ALTER TABLE `' . db_prefix() . "cashi_payment_requests` ADD `payment_recorded` tinyint(1) NOT NULL DEFAULT '0' AFTER `expires_at`;");
        }
    }

    /**
     * Persist a Cashi payment request's QR code and metadata, keyed by reference number
     *
     * @param  array $data
     *
     * @return void
     */
    private function storePaymentRequest($data)
    {
        $this->ensureStorageTable();

        $data['expires_at'] = !empty($data['expires_at']) ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) : null;
        $data['datecreated'] = date('Y-m-d H:i:s');

        // INSERT IGNORE relies on the reference_number UNIQUE KEY rather than a separate
        // SELECT-then-INSERT check, so two near-simultaneous calls for the same reference
        // (e.g. a double-clicked "Pay" button) can't race each other into a duplicate-key
        // database error - the second call is simply a no-op.
        $this->ci->db->query(
            'INSERT IGNORE INTO `' . db_prefix() . 'cashi_payment_requests` '
            . '(invoiceid, reference_number, qr_data_url, qr_content, amount, currency, expires_at, datecreated) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['invoiceid'],
                $data['reference_number'],
                $data['qr_data_url'],
                $data['qr_content'],
                $data['amount'],
                $data['currency'],
                $data['expires_at'],
                $data['datecreated'],
            ]
        );
    }

    /**
     * Retrieve a stored Cashi payment request by reference number
     *
     * @param  string $referenceNumber
     *
     * @return object|null
     */
    public function getStoredPaymentRequest($referenceNumber)
    {
        $this->ensureStorageTable();

        $this->ci->db->where('reference_number', $referenceNumber);

        return $this->ci->db->get(db_prefix() . 'cashi_payment_requests')->row();
    }

    /**
     * Atomically claim the right to record a "paid" payment for this reference number.
     *
     * The webhook, the client-side status poll, and the return-url landing page
     * (verify_payment) can all independently observe a "paid" status for the same reference
     * at nearly the same time. A plain "does a payment record already exist?" SELECT before
     * inserting is a check-then-act race - two callers can both see zero rows and both go on
     * to record the payment, double-crediting the invoice. This flips a payment_recorded flag
     * with the guard in the WHERE clause of the UPDATE itself, so only the caller whose UPDATE
     * actually affects a row is allowed to call addPayment(); every other concurrent caller
     * gets 0 affected rows and backs off.
     *
     * @param  string $referenceNumber
     *
     * @return bool True if this call won the race and should proceed to record the payment
     */
    public function claimPaymentRecording($referenceNumber)
    {
        $this->ensureStorageTable();

        $this->ci->db->where('reference_number', $referenceNumber);
        $this->ci->db->where('payment_recorded', 0);
        $this->ci->db->update(db_prefix() . 'cashi_payment_requests', [
            'payment_recorded' => 1,
        ]);

        if ($this->ci->db->affected_rows() > 0) {
            return true;
        }

        // No stored row for this reference (e.g. it predates this column, or storePaymentRequest
        // failed) - fall back to an atomic insert-based claim keyed by the same unique index.
        if (!$this->getStoredPaymentRequest($referenceNumber)) {
            $claimed = $this->ci->db->query(
                'INSERT IGNORE INTO `' . db_prefix() . 'cashi_payment_requests` (reference_number, invoiceid, payment_recorded, datecreated) VALUES (?, 0, 1, ?)',
                [$referenceNumber, date('Y-m-d H:i:s')]
            );

            return $claimed && $this->ci->db->affected_rows() > 0;
        }

        return false;
    }

    /**
     * Process the payment - called when the client selects Cashi on the invoice payment page
     *
     * @param  array $data
     *
     * @return mixed
     */
    public function process_payment($data)
    {
        $invoice       = $data['invoice'];
        $invoiceNumber = format_invoice_number($invoice->id);
        $description   = str_replace('{invoice_number}', $invoiceNumber, $this->getSetting('description_dashboard'));
        $invoiceUrl    = site_url('invoice/' . $invoice->id . '/' . $invoice->hash);
        $webhookUrl    = site_url('cashi/webhook/' . $invoice->hash);
        $returnUrl     = site_url('cashi/verify_payment?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash);

        $rate = $this->getRateToSdg($invoice->currency_name);

        if ($rate === null) {
            log_activity('Cashi: no exchange rate configured for currency ' . $invoice->currency_name);
            set_alert('danger', _l('cashi_no_exchange_rate'));
            redirect($invoiceUrl);
        }

        $amountInSdg = round($data['amount'] * $rate, 2);

        // If a Cashi payment request is already pending for this invoice AND for the same
        // amount, reuse it instead of creating a new one - the reference number/QR code must
        // stay stable across repeated "Pay with Cashi" clicks, not just across page reloads.
        // A new request is only created if the invoice amount being paid has changed.
        if (!empty($invoice->token)) {
            $existing = $this->getPaymentStatus($invoice->token);

            if ($existing) {
                $existingStatus    = $this->mapStatus($existing['data']['status'] ?? ($existing['status'] ?? ''));
                $existingAmount    = $existing['data']['amount']['value'] ?? ($existing['amount']['value'] ?? null);
                $existingExpiresAt = $existing['data']['expiresAt'] ?? ($existing['expiresAt'] ?? null);
                $stillValid        = $existingExpiresAt ? (strtotime($existingExpiresAt) > time()) : false;

                // Comparing as normalized fixed-2-decimal strings rather than bccomp() (not
                // guaranteed to be compiled into every PHP build, unlike core Perfex which
                // always guards it with function_exists() first) or floats (subject to binary
                // rounding error) - number_format() with the same precision on both sides makes
                // a plain string comparison exact here.
                $existingAmountNormalized = number_format((float) $existingAmount, 2, '.', '');
                $newAmountNormalized      = number_format($amountInSdg, 2, '.', '');

                if ($existingStatus === null && $stillValid && $existingAmount !== null
                    && $existingAmountNormalized === $newAmountNormalized) {
                    redirect(site_url('cashi/pay/' . $invoice->token . '?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash));
                }
            }
        }

        $result = $this->createPaymentRequest([
            'merchantOrderId' => 'INVOICE-' . $invoice->id . '-' . time(),
            'amount'          => [
                'value'    => number_format($amountInSdg, 2, '.', ''),
                'currency' => 'SDG',
            ],
            'description' => $description,
            'callbackUrl' => $webhookUrl,
            'returnUrl'   => $returnUrl,
        ]);

        if (!$result) {
            set_alert('danger', _l('cashi_connection_error'));
            redirect($invoiceUrl);
        }

        $referenceNumber = $result['data']['referenceNumber'] ?? ($result['referenceNumber'] ?? null);

        if (!$referenceNumber) {
            log_activity('Cashi: payment request succeeded but response was missing referenceNumber. Response: ' . json_encode($result));
            set_alert('danger', _l('cashi_connection_error'));
            redirect($invoiceUrl);
        }

        $this->ci->db->where('id', $invoice->id);
        $this->ci->db->update(db_prefix() . 'invoices', [
            'token' => $referenceNumber,
        ]);

        // Cashi only returns the QR code (image + scan link) in this creation response - it is
        // not included again on later status checks - so it is saved now for the pay page to
        // reuse on every subsequent load/reuse instead of having to regenerate it ourselves.
        $this->storePaymentRequest([
            'invoiceid'        => $invoice->id,
            'reference_number' => $referenceNumber,
            'qr_data_url'      => $result['data']['qrCode']['dataUrl'] ?? ($result['qrCode']['dataUrl'] ?? null),
            'qr_content'       => $result['data']['qrCode']['content'] ?? ($result['qrCode']['content'] ?? null),
            'amount'           => $amountInSdg,
            'currency'         => 'SDG',
            'expires_at'       => $result['data']['expiresAt'] ?? ($result['expiresAt'] ?? null),
        ]);

        // Show our own payment page with the QR code, reference number and instructions,
        // instead of sending the customer away to Cashi's own site.
        redirect(site_url('cashi/pay/' . $referenceNumber . '?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash));
    }
}
