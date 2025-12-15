<?php
/**
 * RSL Webhook Service
 * Sends webhook notifications for RSL events
 */

namespace RSL;

use PDO;

class Webhook
{
    private PDO $db;
    private ?string $webhookUrl = null;
    private ?string $webhookSecret = null;
    private array $enabledEvents = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        $stmt = $this->db->prepare(
            "SELECT setting_key, setting_value FROM settings 
             WHERE setting_key IN ('rsl_webhook_url', 'rsl_webhook_secret', 'rsl_webhook_events')"
        );
        $stmt->execute();
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            switch ($row['setting_key']) {
                case 'rsl_webhook_url':
                    $this->webhookUrl = $row['setting_value'] ?: null;
                    break;
                case 'rsl_webhook_secret':
                    $this->webhookSecret = $row['setting_value'] ?: null;
                    break;
                case 'rsl_webhook_events':
                    $this->enabledEvents = json_decode($row['setting_value'] ?: '[]', true) ?: [];
                    break;
            }
        }
    }

    /**
     * Check if webhooks are configured
     */
    public function isConfigured(): bool
    {
        return !empty($this->webhookUrl);
    }

    /**
     * Check if a specific event type is enabled
     */
    public function isEventEnabled(string $eventType): bool
    {
        return in_array($eventType, $this->enabledEvents, true);
    }

    /**
     * Send a webhook notification
     */
    public function send(string $eventType, array $payload): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        if (!$this->isEventEnabled($eventType)) {
            return false;
        }

        $webhookPayload = [
            'event' => $eventType,
            'timestamp' => date('c'),
            'data' => $payload
        ];

        $jsonPayload = json_encode($webhookPayload);
        $signature = $this->generateSignature($jsonPayload);

        $ch = curl_init($this->webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-RSL-Webhook-Event: ' . $eventType,
                'X-RSL-Webhook-Signature: ' . $signature,
                'X-RSL-Webhook-Timestamp: ' . time()
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $success = $httpCode >= 200 && $httpCode < 300;

        // Log the webhook
        $this->logWebhook($eventType, $webhookPayload, $httpCode, $response, $success, $error);

        return $success;
    }

    /**
     * Generate HMAC signature for webhook payload
     */
    private function generateSignature(string $payload): string
    {
        if (empty($this->webhookSecret)) {
            return '';
        }
        return hash_hmac('sha256', $payload, $this->webhookSecret);
    }

    /**
     * Log webhook delivery attempt
     */
    private function logWebhook(
        string $eventType,
        array $payload,
        ?int $responseStatus,
        ?string $responseBody,
        bool $success,
        ?string $errorMessage
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO rsl_webhook_logs 
                 (event_type, payload, response_status, response_body, success, error_message)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $eventType,
                json_encode($payload),
                $responseStatus,
                $responseBody ? substr($responseBody, 0, 65535) : null,
                $success ? 1 : 0,
                $errorMessage
            ]);
        } catch (\Exception $e) {
            error_log("Failed to log webhook: " . $e->getMessage());
        }
    }

    /**
     * Get recent webhook logs
     */
    public function getLogs(int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM rsl_webhook_logs ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Retry failed webhooks
     */
    public function retryFailed(int $logId): bool
    {
        $stmt = $this->db->prepare("SELECT * FROM rsl_webhook_logs WHERE id = ?");
        $stmt->execute([$logId]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$log) {
            return false;
        }

        $payload = json_decode($log['payload'], true);
        if (!$payload || !isset($payload['event'])) {
            return false;
        }

        return $this->send($payload['event'], $payload['data'] ?? []);
    }

    // ==========================================
    // Convenience methods for common events
    // ==========================================

    /**
     * Notify when a new client registers
     */
    public function notifyClientRegistered(array $client): bool
    {
        return $this->send('client.registered', [
            'client_id' => $client['client_id'] ?? null,
            'name' => $client['name'] ?? null,
            'client_type' => $client['client_type'] ?? null,
            'contact_email' => $client['contact_email'] ?? null,
            'website' => $client['website'] ?? null,
            'intended_use' => $client['intended_use'] ?? null,
            'registered_at' => date('c')
        ]);
    }

    /**
     * Notify when a client is approved
     */
    public function notifyClientApproved(array $client): bool
    {
        return $this->send('client.approved', [
            'client_id' => $client['client_id'] ?? null,
            'name' => $client['name'] ?? null,
            'approved_at' => date('c')
        ]);
    }

    /**
     * Notify when a client is rejected
     */
    public function notifyClientRejected(array $client, string $reason = ''): bool
    {
        return $this->send('client.rejected', [
            'client_id' => $client['client_id'] ?? null,
            'name' => $client['name'] ?? null,
            'reason' => $reason,
            'rejected_at' => date('c')
        ]);
    }

    /**
     * Notify when a token is issued
     */
    public function notifyTokenIssued(array $token, array $client): bool
    {
        return $this->send('token.issued', [
            'token_id' => $token['id'] ?? null,
            'client_id' => $client['client_id'] ?? null,
            'client_name' => $client['name'] ?? null,
            'scope' => $token['scope'] ?? null,
            'expires_at' => $token['expires_at'] ?? null,
            'issued_at' => date('c')
        ]);
    }

    /**
     * Notify when a token is revoked
     */
    public function notifyTokenRevoked(array $token): bool
    {
        return $this->send('token.revoked', [
            'token_id' => $token['id'] ?? null,
            'access_token' => substr($token['access_token'] ?? '', 0, 8) . '...', // Partial for reference
            'revoked_at' => date('c')
        ]);
    }

    /**
     * Notify when content is accessed with a license
     */
    public function notifyLicensedAccess(array $accessLog): bool
    {
        return $this->send('access.licensed', [
            'client_id' => $accessLog['client_id'] ?? null,
            'content_url' => $accessLog['content_url'] ?? null,
            'license_id' => $accessLog['license_id'] ?? null,
            'action' => $accessLog['action'] ?? null,
            'accessed_at' => date('c')
        ]);
    }

    /**
     * Notify for pay-per-use billing events
     */
    public function notifyPaymentRequired(array $client, string $contentUrl, float $amount, string $currency = 'USD'): bool
    {
        return $this->send('payment.required', [
            'client_id' => $client['client_id'] ?? null,
            'client_name' => $client['name'] ?? null,
            'content_url' => $contentUrl,
            'amount' => $amount,
            'currency' => $currency,
            'timestamp' => date('c')
        ]);
    }
}
