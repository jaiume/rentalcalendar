<?php

namespace App\DAO;

use PDO;
use PDOException;

class PaymentDAO extends BaseDAO
{
    public const VALID_STATUSES = ['created', 'completed', 'failed', 'refunded'];

    protected function getTableName(): string
    {
        return 'payments';
    }

    protected function getPrimaryKey(): string
    {
        return 'payment_id';
    }

    /**
     * Insert a new payment row in `created` state.
     *
     * @param array{
     *   portal_group_id: int,
     *   item_type: string,
     *   item_reference?: ?string,
     *   description?: ?string,
     *   paypal_order_id?: ?string,
     *   provider?: string,
     *   amount_cents: int,
     *   currency: string
     * } $row
     */
    public function create(array $row): int
    {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO payments
                    (portal_group_id, item_type, item_reference, description,
                     paypal_order_id, provider, amount_cents, currency, status)
                 VALUES
                    (:portal_group_id, :item_type, :item_reference, :description,
                     :paypal_order_id, :provider, :amount_cents, :currency, :status)'
            );
            $stmt->execute([
                'portal_group_id' => $row['portal_group_id'],
                'item_type' => $row['item_type'],
                'item_reference' => $row['item_reference'] ?? null,
                'description' => $row['description'] ?? null,
                'paypal_order_id' => $row['paypal_order_id'] ?? null,
                'provider' => $row['provider'] ?? 'paypal',
                'amount_cents' => $row['amount_cents'],
                'currency' => strtoupper($row['currency']),
                'status' => 'created',
            ]);
            return (int) $this->db->lastInsertId();
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to create payment: ' . $e->getMessage(), 0, $e);
        }
    }

    public function findByPaypalOrderId(string $orderId): ?array
    {
        try {
            $stmt = $this->db->prepare('SELECT * FROM payments WHERE paypal_order_id = :id');
            $stmt->execute(['id' => $orderId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to find payment by PayPal order id: ' . $e->getMessage(), 0, $e);
        }
    }

    public function markCompleted(int $paymentId, ?string $payerEmail, ?string $ipAddress): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE payments
                    SET status = :status,
                        payer_email = :payer_email,
                        ip_address = :ip,
                        completed_at = CURRENT_TIMESTAMP
                  WHERE payment_id = :id'
            );
            $stmt->execute([
                'status' => 'completed',
                'payer_email' => $payerEmail,
                'ip' => $ipAddress,
                'id' => $paymentId,
            ]);
            return $stmt->rowCount() >= 0;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to mark payment completed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function markFailed(int $paymentId, ?string $ipAddress = null): bool
    {
        try {
            $stmt = $this->db->prepare(
                'UPDATE payments
                    SET status = :status,
                        ip_address = COALESCE(:ip, ip_address)
                  WHERE payment_id = :id'
            );
            $stmt->execute([
                'status' => 'failed',
                'ip' => $ipAddress,
                'id' => $paymentId,
            ]);
            return $stmt->rowCount() >= 0;
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to mark payment failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{portal_group_id?: int, item_type?: string, status?: string, from?: string, to?: string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function findForAudit(array $filters = []): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['portal_group_id'])) {
            $where[] = 'p.portal_group_id = :portal_group_id';
            $params['portal_group_id'] = (int) $filters['portal_group_id'];
        }
        if (!empty($filters['item_type'])) {
            $where[] = 'p.item_type = :item_type';
            $params['item_type'] = $filters['item_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'p.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['from'])) {
            $where[] = 'p.created_at >= :from';
            $params['from'] = $filters['from'];
        }
        if (!empty($filters['to'])) {
            $where[] = 'p.created_at <= :to';
            $params['to'] = $filters['to'];
        }

        $sql = 'SELECT p.*, pg.name AS portal_group_name, pg.slug AS portal_group_slug
                  FROM payments p
                  JOIN portal_groups pg ON pg.portal_group_id = p.portal_group_id';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY p.created_at DESC LIMIT 500';

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to fetch payments: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return string[]
     */
    public function distinctItemTypes(): array
    {
        try {
            $stmt = $this->db->query('SELECT DISTINCT item_type FROM payments ORDER BY item_type');
            return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            throw new \RuntimeException('Failed to fetch payment item types: ' . $e->getMessage(), 0, $e);
        }
    }
}
