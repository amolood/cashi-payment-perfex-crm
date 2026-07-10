<?php

defined('BASEPATH') or exit('No direct script access allowed');

/**
 * @property-read Cashi_gateway $cashi_gateway
 */
class Cashi extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->library('cashi/cashi_gateway');
    }

    /**
     * Serve a QR code image for the given reference number.
     *
     * Cashi's status-check endpoint does not include the QR code (only the initial
     * payment-request creation response does), so this is generated on our side instead of
     * depending on a value that isn't available on page reloads - the scan-and-pay link
     * follows Cashi's own documented, deterministic format.
     *
     * @param  string $referenceNumber
     *
     * @return mixed
     */
    public function qr($referenceNumber = null)
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$referenceNumber || !$invoice || $invoice->token != $referenceNumber) {
            show_404();
        }

        // The My Cashi app's scanner expects the full scan-and-pay link, not a bare reference
        // number - scanning a QR with just the number fails in the app even though the app
        // accepts the same number fine when typed in manually.
        $content = 'https://www.getcashi.com/ScanAndPay?ref=cashipay|' . $referenceNumber;

        // TCPDF's QR encoder defaults to sampling a random subset of mask patterns for
        // performance (QR_FIND_FROM_RANDOM), which makes the exact module layout it picks
        // non-deterministic between requests for the *same* content - the reference number
        // must render the exact same QR image every time the page is reloaded, so this forces
        // it to deterministically evaluate all 8 mask patterns instead.
        if (!defined('QR_FIND_FROM_RANDOM')) {
            define('QR_FIND_FROM_RANDOM', false);
        }

        require_once(APPPATH . 'vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php');
        $barcode = new TCPDF2DBarcode($content, 'QRCODE,H');
        $barcode->getBarcodePNG(6, 6);
        exit;
    }

    /**
     * Handle the Cashi webhook/callback - Cashi calls this url once the payment request is resolved
     *
     * @param  string $hash Invoice hash, used to look up which invoice this callback belongs to
     *
     * @return mixed
     */
    public function webhook($hash = null)
    {
        $referenceNumber = $this->input->post('referenceNumber') ?? $this->input->get('referenceNumber');

        if (!$hash || !$referenceNumber) {
            log_activity('Cashi webhook called with missing hash or referenceNumber');

            return;
        }

        $this->db->where('hash', $hash);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$invoice || $invoice->token != $referenceNumber) {
            log_activity('Cashi webhook: invoice not found or reference mismatch. Reference: ' . $referenceNumber);

            return;
        }

        $result = $this->cashi_gateway->getPaymentStatus($referenceNumber);

        if (!$result) {
            log_activity('Cashi webhook: could not fetch payment status. Reference: ' . $referenceNumber);

            return;
        }

        $rawStatus = $result['data']['status'] ?? ($result['status'] ?? '');
        $status    = $this->cashi_gateway->mapStatus($rawStatus);

        if ($status !== 'paid') {
            log_activity('Cashi webhook: payment not completed. Status: ' . $rawStatus);

            return;
        }

        if (!$this->cashi_gateway->claimPaymentRecording($referenceNumber)) {
            log_activity('Cashi webhook: payment already recorded (or being recorded) by another request. Reference: ' . $referenceNumber);

            return;
        }

        // Cashi settles in SDG, but the payment record must be booked in the invoice's own
        // currency for Perfex's paid/partial totals to stay correct - the amount actually due
        // (not whatever Cashi reports back) is what gets recorded.
        $amountDue = get_invoice_total_left_to_pay($invoice->id, $invoice->total);

        $this->cashi_gateway->addPayment([
            'amount'        => $amountDue,
            'invoiceid'     => $invoice->id,
            'transactionid' => $referenceNumber,
        ]);

        log_activity('Cashi payment recorded. Invoice ID: ' . $invoice->id . '. Reference: ' . $referenceNumber);
    }

    /**
     * Our own payment page - shown right after the payment request is created, instead of
     * sending the customer to Cashi's own site. Displays the reference number, QR code and
     * instructions for paying via the My Cashi app.
     *
     * @param  string $referenceNumber
     *
     * @return mixed
     */
    public function pay($referenceNumber = null)
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$referenceNumber || !$invoice || $invoice->token != $referenceNumber) {
            show_404();
        }

        $result = $this->cashi_gateway->getPaymentStatus($referenceNumber);

        if (!$result) {
            set_alert('danger', _l('cashi_connection_error'));
            redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
        }

        $rawStatus = $result['data']['status'] ?? ($result['status'] ?? '');
        $status    = $this->cashi_gateway->mapStatus($rawStatus);

        // Already resolved - no point showing the pay screen again
        if ($status === 'paid' || $status === 'failed' || $status === 'expired') {
            redirect(site_url('cashi/verify_payment?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash));
        }

        $stored = $this->cashi_gateway->getStoredPaymentRequest($referenceNumber);

        $data['invoice']         = $invoice;
        $data['referenceNumber'] = $referenceNumber;
        $data['amount']          = $result['data']['amount']['value'] ?? ($result['amount']['value'] ?? null);
        $data['currency']        = $result['data']['amount']['currency'] ?? ($result['amount']['currency'] ?? 'SDG');
        $data['expiresAt']       = $result['data']['expiresAt'] ?? ($result['expiresAt'] ?? null);
        // Prefer the QR code Cashi itself returned when the request was created (saved in our
        // own table, since Cashi does not send it again on status checks); fall back to
        // generating our own only for requests created before this was persisted.
        $data['qrImageUrl']      = ($stored && !empty($stored->qr_data_url))
            ? $stored->qr_data_url
            : site_url('cashi/qr/' . $referenceNumber . '?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash);
        $data['statusUrl']       = site_url('cashi/status/' . $referenceNumber . '?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash);
        $data['verifyUrl']       = site_url('cashi/verify_payment?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash);
        $data['invoiceUrl']      = site_url('invoice/' . $invoice->id . '/' . $invoice->hash);
        $data['pollInterval']    = $this->cashi_gateway->getPollInterval();
        $data['title']           = _l('cashi_pay_title');

        echo payment_gateway_head($data['title']);
        $this->load->view('cashi_pay', $data);
        echo payment_gateway_footer();
    }

    /**
     * JSON status-check endpoint polled by the pay page's JavaScript
     *
     * @param  string $referenceNumber
     *
     * @return mixed
     */
    public function status($referenceNumber = null)
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if (!$referenceNumber || !$invoice || $invoice->token != $referenceNumber) {
            show_404();
        }

        $result = $this->cashi_gateway->getPaymentStatus($referenceNumber);

        $rawStatus = $result['data']['status'] ?? ($result['status'] ?? '');
        $status    = $result ? $this->cashi_gateway->mapStatus($rawStatus) : null;

        echo json_encode([
            'status'     => $status,
            'redirect'   => in_array($status, ['paid', 'failed', 'expired'])
                ? site_url('cashi/verify_payment?invoiceid=' . $invoice->id . '&hash=' . $invoice->hash)
                : null,
        ]);
    }

    /**
     * Landing page the client is redirected back to from Cashi's checkout - verifies status and shows a message
     *
     * @return mixed
     */
    public function verify_payment()
    {
        $invoiceid = $this->input->get('invoiceid');
        $hash      = $this->input->get('hash');
        check_invoice_restrictions($invoiceid, $hash);

        $this->db->where('id', $invoiceid);
        $invoice = $this->db->get(db_prefix() . 'invoices')->row();

        if ($invoice->token) {
            $result = $this->cashi_gateway->getPaymentStatus($invoice->token);

            if ($result) {
                $rawStatus = $result['data']['status'] ?? ($result['status'] ?? '');
                $status    = $this->cashi_gateway->mapStatus($rawStatus);

                if ($status === 'paid' && $this->cashi_gateway->claimPaymentRecording($invoice->token)) {
                    $amountDue = get_invoice_total_left_to_pay($invoice->id, $invoice->total);
                    $this->cashi_gateway->addPayment([
                        'amount'        => $amountDue,
                        'invoiceid'     => $invoice->id,
                        'transactionid' => $invoice->token,
                    ]);
                }

                if ($status === 'paid') {
                    set_alert('success', _l('online_payment_recorded_success'));
                } elseif ($status === 'failed' || $status === 'expired') {
                    set_alert('danger', _l('cashi_payment_not_completed'));
                }
            }
        }

        redirect(site_url('invoice/' . $invoice->id . '/' . $invoice->hash));
    }
}
