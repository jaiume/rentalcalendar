<?php

namespace App\Services;

use App\Models\PortalGroup;

/**
 * Centralises admin notification emails for guest-portal events
 * (laundry payments, supply requests). Wraps UtilityService::sendEmail
 * with a single recipient lookup (`portal.admin_email`) and a single
 * link prefix (`portal.admin_url`).
 *
 * Public methods are intentionally `void` and never throw — failures
 * are logged via LogService so a bad SMTP server cannot affect a
 * guest's HTTP response. When `portal.admin_email` is blank the
 * service short-circuits with a debug log and no SMTP attempt is made.
 */
final class AdminNotificationService
{
    public function __construct(
        private readonly UtilityService $utility,
        private readonly ConfigService $config
    ) {
    }

    /**
     * @param array<string, mixed> $payment payments table row from PaymentDAO
     */
    public function notifyLaundryPayment(
        PortalGroup $portal,
        array $payment,
        ?string $payerEmail,
        int $amountCents,
        string $currency
    ): void {
        $recipient = $this->recipient();
        if ($recipient === null) {
            LogService::debug('Admin notifications disabled (laundry payment)', [
                'portal_slug' => $portal->slug(),
            ]);
            return;
        }

        try {
            $subject = sprintf('Laundry payment received — %s', $portal->name());
            $body = $this->buildLaundryPaymentBody(
                $portal,
                $payment,
                $payerEmail,
                $amountCents,
                $currency
            );
            $this->dispatch($recipient, $subject, $body, [
                'event' => 'laundry_payment',
                'portal_slug' => $portal->slug(),
                'paypal_order_id' => $payment['paypal_order_id'] ?? null,
            ]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'Failed to build/send laundry payment admin email');
        }
    }

    /**
     * @param string[] $checkedItems
     */
    public function notifySupplyRequest(
        PortalGroup $portal,
        string $propertyName,
        array $checkedItems,
        string $extraNotes,
        int $supplyRequestId
    ): void {
        $recipient = $this->recipient();
        if ($recipient === null) {
            LogService::debug('Admin notifications disabled (supply request)', [
                'portal_slug' => $portal->slug(),
                'supply_request_id' => $supplyRequestId,
            ]);
            return;
        }

        try {
            $subject = sprintf(
                'Supply request — %s (%s)',
                $propertyName,
                $portal->name()
            );
            $body = $this->buildSupplyRequestBody(
                $portal,
                $propertyName,
                $checkedItems,
                $extraNotes,
                $supplyRequestId
            );
            $this->dispatch($recipient, $subject, $body, [
                'event' => 'supply_request',
                'portal_slug' => $portal->slug(),
                'supply_request_id' => $supplyRequestId,
            ]);
        } catch (\Throwable $e) {
            LogService::exception($e, 'Failed to build/send supply request admin email');
        }
    }

    private function recipient(): ?string
    {
        $email = trim((string) $this->config::get('portal.admin_email', ''));
        return $email === '' ? null : $email;
    }

    private function adminUrl(): ?string
    {
        $url = trim((string) $this->config::get('portal.admin_url', ''));
        return $url === '' ? null : rtrim($url, '/');
    }

    /**
     * @param array<string, mixed> $logContext
     */
    private function dispatch(string $to, string $subject, string $body, array $logContext): void
    {
        $sent = $this->utility->sendEmail($to, $subject, $body, true);
        if (!$sent) {
            // UtilityService already logged the underlying error; add an
            // event-level breadcrumb so it's easy to correlate against
            // the originating guest action.
            LogService::warning('Admin notification email did not send', $logContext + [
                'subject' => $subject,
            ]);
            return;
        }
        LogService::info('Admin notification email sent', $logContext + [
            'subject' => $subject,
        ]);
    }

    /**
     * @param array<string, mixed> $payment
     */
    private function buildLaundryPaymentBody(
        PortalGroup $portal,
        array $payment,
        ?string $payerEmail,
        int $amountCents,
        string $currency
    ): string {
        $amount = htmlspecialchars(
            sprintf('%s %s', strtoupper($currency), PayPalService::centsToDecimalString($amountCents)),
            ENT_QUOTES,
            'UTF-8'
        );
        $orderId = htmlspecialchars(
            (string) ($payment['paypal_order_id'] ?? ''),
            ENT_QUOTES,
            'UTF-8'
        );
        $payer = htmlspecialchars(
            $payerEmail !== null && $payerEmail !== '' ? $payerEmail : '(not provided)',
            ENT_QUOTES,
            'UTF-8'
        );
        $portalName = htmlspecialchars($portal->name(), ENT_QUOTES, 'UTF-8');
        $portalSlug = htmlspecialchars($portal->slug(), ENT_QUOTES, 'UTF-8');
        $propertyName = htmlspecialchars(
            $this->resolvePropertyName($portal, $payment['item_reference'] ?? null),
            ENT_QUOTES,
            'UTF-8'
        );
        $timestamp = htmlspecialchars(
            date('Y-m-d H:i:s T'),
            ENT_QUOTES,
            'UTF-8'
        );

        $rows = [
            ['Portal', sprintf('%s (%s)', $portalName, $portalSlug)],
            ['Property', $propertyName],
            ['Amount', $amount],
            ['PayPal order ID', $orderId !== '' ? $orderId : '(unknown)'],
            ['Payer email', $payer],
            ['Captured at', $timestamp],
        ];

        return $this->renderEmail(
            'Laundry payment received',
            'A laundry payment has just been completed via the guest portal.',
            $rows,
            $this->adminLink('/admin/payments', 'View in admin')
        );
    }

    /**
     * @param string[] $checkedItems
     */
    private function buildSupplyRequestBody(
        PortalGroup $portal,
        string $propertyName,
        array $checkedItems,
        string $extraNotes,
        int $supplyRequestId
    ): string {
        $portalName = htmlspecialchars($portal->name(), ENT_QUOTES, 'UTF-8');
        $propertySafe = htmlspecialchars($propertyName, ENT_QUOTES, 'UTF-8');
        $timestamp = htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8');

        if (empty($checkedItems)) {
            $itemsHtml = '<em>(none picked from suggestions)</em>';
        } else {
            $itemsHtml = '<ul>';
            foreach ($checkedItems as $item) {
                $itemsHtml .= '<li>' . htmlspecialchars((string) $item, ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $itemsHtml .= '</ul>';
        }

        $notes = trim($extraNotes);
        $notesHtml = $notes === ''
            ? '<em>(no additional notes)</em>'
            : nl2br(htmlspecialchars($notes, ENT_QUOTES, 'UTF-8'));

        $rows = [
            ['Portal', $portalName],
            ['Property', $propertySafe],
            ['Items requested', $itemsHtml],
            ['Notes', $notesHtml],
            ['Request ID', '#' . $supplyRequestId],
            ['Submitted at', $timestamp],
        ];

        return $this->renderEmail(
            'New supply request',
            'A guest has just submitted a supply request via the portal.',
            $rows,
            $this->adminLink('/admin/supply-requests', 'View in admin')
        );
    }

    /**
     * @param mixed $propertyId raw value from payments.item_reference (string|null)
     */
    private function resolvePropertyName(PortalGroup $portal, $propertyId): string
    {
        if ($propertyId === null || $propertyId === '') {
            return '(not specified)';
        }
        $id = (int) $propertyId;
        if ($id <= 0) {
            return '(not specified)';
        }
        foreach ($portal->properties() as $prop) {
            if ((int) ($prop['property_id'] ?? 0) === $id) {
                return (string) ($prop['property_name'] ?? '#' . $id);
            }
        }
        return '#' . $id;
    }

    private function adminLink(string $path, string $label): string
    {
        $base = $this->adminUrl();
        if ($base === null) {
            return '';
        }
        $url = $base . '/' . ltrim($path, '/');
        return sprintf(
            '<p style="margin-top:24px"><a href="%s" class="button">%s</a></p>',
            htmlspecialchars($url, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
        );
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rows
     */
    private function renderEmail(string $heading, string $intro, array $rows, string $cta): string
    {
        $appName = htmlspecialchars(
            (string) $this->config::get('app.name', 'Rental Calendar'),
            ENT_QUOTES,
            'UTF-8'
        );
        $headingSafe = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $introSafe = htmlspecialchars($intro, ENT_QUOTES, 'UTF-8');

        $rowsHtml = '';
        foreach ($rows as [$label, $value]) {
            $rowsHtml .= '<tr><th style="text-align:left;padding:6px 12px 6px 0;vertical-align:top;color:#6c757d;font-weight:normal">'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '</th><td style="padding:6px 0;vertical-align:top">'
                . $value
                . '</td></tr>';
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.5; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        table { border-collapse: collapse; width: 100%; margin-top: 12px; }
        .button { display: inline-block; padding: 10px 18px; background: #0d6efd; color: #fff; text-decoration: none; border-radius: 6px; }
        .footer { margin-top: 28px; padding-top: 16px; border-top: 1px solid #dee2e6; font-size: 12px; color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <h2 style="margin-top:0">{$headingSafe}</h2>
        <p>{$introSafe}</p>
        <table>{$rowsHtml}</table>
        {$cta}
        <div class="footer">
            <p>This is an automated message from {$appName}. Please do not reply to this email.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
