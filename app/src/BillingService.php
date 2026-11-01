<?php /* © 2026 Indisse-BTS. All rights reserved. Proprietary — licensed, not sold. See LICENSE.txt. */
declare(strict_types=1);

namespace FlxCoreHC;

/**
 * Stripe billing tied to plans.
 *
 * Degrades safely: when Stripe keys are absent, isConfigured() is false and the UI
 * shows setup guidance rather than calling out. All Stripe REST calls go over cURL
 * (no Composer/SDK needed). The webhook handler verifies Stripe's signature scheme
 * and maps the subscription's price back to a plan, updating the tenant's plan_id
 * and billing_status. Tenant isolation is unchanged.
 */
final class BillingService
{
    private const API = 'https://api.stripe.com/v1/';

    public static function isConfigured(): bool
    {
        return (string) config('stripe_secret_key', '') !== ''
            && (string) config('stripe_webhook_secret', '') !== '';
    }

    /**
     * Evaluate a tenant's access state from its billing status.
     * Returns ['locked'=>bool, 'level'=>'ok|info|warn|locked', 'notice'=>string].
     * Billing is only enforced when Stripe is configured; otherwise always open.
     */
    public static function state(array $tenant): array
    {
        $status = $tenant['billing_status'] ?? 'none';
        $ok = ['locked' => false, 'level' => 'ok', 'notice' => ''];
        if (!self::isConfigured() || $status === 'none') return $ok;

        $now       = time();
        $periodEnd = !empty($tenant['current_period_end']) ? strtotime($tenant['current_period_end']) : null;
        $grace     = (int) config('grace_days', 7) * 86400;

        switch ($status) {
            case 'active':
                return $ok;
            case 'trialing':
                if ($periodEnd && $now > $periodEnd) {
                    return ['locked' => true, 'level' => 'locked', 'notice' => 'Your free trial has ended. Choose a plan to continue.'];
                }
                $days = $periodEnd ? max(0, (int) ceil(($periodEnd - $now) / 86400)) : null;
                $note = $days !== null ? ('Free trial — ' . $days . ' day' . ($days === 1 ? '' : 's') . ' left.') : 'Free trial active.';
                return ['locked' => false, 'level' => 'info', 'notice' => $note];
            case 'past_due':
                $since = !empty($tenant['past_due_since']) ? strtotime($tenant['past_due_since']) : $now;
                if ($now > $since + $grace) {
                    return ['locked' => true, 'level' => 'locked', 'notice' => 'Your subscription is past due. Update payment to restore access.'];
                }
                $left = max(0, (int) ceil(($since + $grace - $now) / 86400));
                return ['locked' => false, 'level' => 'warn', 'notice' => "Payment failed — please update billing within {$left} day" . ($left === 1 ? '' : 's') . '.'];
            case 'canceled':
                if ($periodEnd && $now <= $periodEnd) {
                    return ['locked' => false, 'level' => 'warn', 'notice' => 'Your subscription is canceled and access ends at the period end.'];
                }
                return ['locked' => true, 'level' => 'locked', 'notice' => 'Your subscription is canceled. Choose a plan to continue.'];
        }
        return $ok;
    }

    // ---- Checkout / portal (require live keys; guarded) ----

    /** Create a Checkout Session for a plan; returns [ok, urlOrError]. */
    public static function checkout(array $tenant, array $plan): array
    {
        if (!self::isConfigured())                 return [false, 'Billing is not configured.'];
        if (empty($plan['stripe_price_id']))       return [false, 'This plan has no Stripe price configured.'];
        $base = rtrim((string) config('app_url', ''), '/') . BASE_PATH;
        $params = [
            'mode' => 'subscription',
            'line_items[0][price]'    => $plan['stripe_price_id'],
            'line_items[0][quantity]' => 1,
            'success_url' => $base . '/billing?ok=1',
            'cancel_url'  => $base . '/billing',
            'client_reference_id' => $tenant['id'],
            'metadata[tenant_id]' => $tenant['id'],
            'metadata[plan_id]'   => $plan['id'],
        ];
        if (!empty($tenant['stripe_customer_id'])) {
            $params['customer'] = $tenant['stripe_customer_id'];
        }
        [$ok, $res] = self::post('checkout/sessions', $params);
        return $ok ? [true, $res['url'] ?? ''] : [false, $res];
    }

    /** Create an EMBEDDED Checkout session; returns [ok, clientSecret|error]. */
    public static function embeddedSession(array $tenant, array $plan): array
    {
        if (!self::isConfigured())           return [false, 'Billing is not configured.'];
        if (empty($plan['stripe_price_id'])) return [false, 'This plan has no Stripe price configured.'];
        $base = rtrim((string) config('app_url', ''), '/') . BASE_PATH;
        $params = [
            'ui_mode' => 'embedded',
            'mode'    => 'subscription',
            'line_items[0][price]'    => $plan['stripe_price_id'],
            'line_items[0][quantity]' => 1,
            'return_url' => $base . '/billing?session_id={CHECKOUT_SESSION_ID}',
            'client_reference_id' => $tenant['id'],
            'metadata[tenant_id]' => $tenant['id'],
            'metadata[plan_id]'   => $plan['id'],
        ];
        if (!empty($tenant['stripe_customer_id'])) $params['customer'] = $tenant['stripe_customer_id'];
        [$ok, $res] = self::post('checkout/sessions', $params);
        return $ok ? [true, $res['client_secret'] ?? ''] : [false, $res];
    }

    public static function publishableKey(): string
    {
        return (string) config('stripe_publishable_key', '');
    }

    /** Open the Stripe billing portal for an existing customer. */
    public static function portal(array $tenant): array
    {
        if (!self::isConfigured() || empty($tenant['stripe_customer_id'])) {
            return [false, 'No billing account yet.'];
        }
        [$ok, $res] = self::post('billing_portal/sessions', [
            'customer'   => $tenant['stripe_customer_id'],
            'return_url' => rtrim((string) config('app_url', ''), '/') . BASE_PATH . '/billing',
        ]);
        return $ok ? [true, $res['url'] ?? ''] : [false, $res];
    }

    // ---- Webhook ----

    /** Verify Stripe's 't=,v1=' signature header (HMAC-SHA256 over "t.payload"). */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        $t = null; $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') $t = $v;
            if ($k === 'v1') $sigs[] = $v;
        }
        if ($t === null || $sigs === []) return false;
        if (abs(time() - (int) $t) > $tolerance) return false;
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $s) { if (hash_equals($expected, $s)) return true; }
        return false;
    }

    /** Handle a raw webhook request. Returns [httpStatus, message]. */
    public static function handleWebhook(string $payload, string $sigHeader): array
    {
        $secret = (string) config('stripe_webhook_secret', '');
        if ($secret === '' || !self::verifySignature($payload, $sigHeader, $secret)) {
            return [400, 'invalid signature'];
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['type'])) return [400, 'bad payload'];
        $obj = $event['data']['object'] ?? [];

        switch ($event['type']) {
            case 'checkout.session.completed':
                $tenantId = $obj['metadata']['tenant_id'] ?? ($obj['client_reference_id'] ?? null);
                if ($tenantId) {
                    self::setTenantBilling($tenantId, [
                        'stripe_customer_id'     => $obj['customer'] ?? null,
                        'stripe_subscription_id' => $obj['subscription'] ?? null,
                        'billing_status'         => 'active',
                    ]);
                    if (!empty($obj['metadata']['plan_id'])) self::setTenantPlan($tenantId, $obj['metadata']['plan_id']);
                }
                break;

            case 'customer.subscription.updated':
            case 'customer.subscription.created':
            case 'customer.subscription.deleted':
                $subId  = $obj['id'] ?? null;
                $status = $event['type'] === 'customer.subscription.deleted' ? 'canceled' : ($obj['status'] ?? 'active');
                $priceId = $obj['items']['data'][0]['price']['id'] ?? null;
                $periodEnd = !empty($obj['current_period_end']) ? gmdate('Y-m-d H:i:s', (int) $obj['current_period_end']) : null;
                $tenant = $subId ? self::tenantBySubscription($subId) : null;
                if (!$tenant && !empty($obj['customer'])) $tenant = self::tenantByCustomer($obj['customer']);
                if ($tenant) {
                    $fields = [
                        'stripe_subscription_id' => $subId,
                        'billing_status'         => $status,
                        'current_period_end'     => $periodEnd,
                    ];
                    if ($status === 'active') $fields['past_due_since'] = null;
                    self::setTenantBilling($tenant['id'], $fields);
                    if ($priceId && ($plan = self::planByPrice($priceId))) self::setTenantPlan($tenant['id'], $plan['id']);
                }
                break;

            case 'invoice.payment_failed':
                if (!empty($obj['customer']) && ($tenant = self::tenantByCustomer($obj['customer']))) {
                    $fields = ['billing_status' => 'past_due'];
                    if (empty($tenant['past_due_since'])) $fields['past_due_since'] = utc_now();
                    self::setTenantBilling($tenant['id'], $fields);
                }
                break;

            case 'invoice.payment_succeeded':
                if (!empty($obj['customer']) && ($tenant = self::tenantByCustomer($obj['customer']))) {
                    self::setTenantBilling($tenant['id'], ['billing_status' => 'active', 'past_due_since' => null]);
                }
                break;
        }
        return [200, 'ok'];
    }

    // ---- helpers ----

    private static function post(string $path, array $params): array
    {
        if (!function_exists('curl_init')) {
            return [false, 'Server is missing the PHP cURL extension required for online billing.'];
        }
        $ch = \curl_init(self::API . $path);
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => config('stripe_secret_key', '') . ':',
        ]);
        $body = \curl_exec($ch);
        $code = \curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = \curl_error($ch);
        \curl_close($ch);
        if ($body === false) return [false, 'Stripe request failed: ' . $err];
        $json = json_decode((string) $body, true);
        if ($code >= 200 && $code < 300) return [true, $json];
        return [false, $json['error']['message'] ?? 'Stripe error'];
    }

    private static function setTenantBilling(string $tenantId, array $fields): void
    {
        $cols = []; $vals = [];
        foreach ($fields as $k => $v) { $cols[] = "$k = ?"; $vals[] = $v; }
        if (!$cols) return;
        $vals[] = $tenantId;
        Database::pdo()->prepare("UPDATE tenants SET " . implode(', ', $cols) . " WHERE id = ?")->execute($vals);
    }
    private static function setTenantPlan(string $tenantId, string $planId): void
    {
        Database::pdo()->prepare("UPDATE tenants SET plan_id = ? WHERE id = ?")->execute([$planId, $tenantId]);
    }
    private static function tenantBySubscription(string $subId): ?array
    {
        $q = Database::pdo()->prepare("SELECT * FROM tenants WHERE stripe_subscription_id = ? LIMIT 1");
        $q->execute([$subId]); $r = $q->fetch(); return $r === false ? null : $r;
    }
    private static function tenantByCustomer(string $custId): ?array
    {
        $q = Database::pdo()->prepare("SELECT * FROM tenants WHERE stripe_customer_id = ? LIMIT 1");
        $q->execute([$custId]); $r = $q->fetch(); return $r === false ? null : $r;
    }
    private static function planByPrice(string $priceId): ?array
    {
        $q = Database::pdo()->prepare("SELECT * FROM plans WHERE stripe_price_id = ? LIMIT 1");
        $q->execute([$priceId]); $r = $q->fetch(); return $r === false ? null : $r;
    }
}
