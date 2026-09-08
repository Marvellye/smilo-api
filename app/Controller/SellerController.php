<?php

declare(strict_types=1);

namespace Smilo\Controller;

use flight\Engine;

class SellerController
{
    private Engine $app;
    private \PDO $db;

    public function __construct(Engine $app, \PDO $db)
    {
        $this->app = $app;
        $this->db  = $db;
    }

    /** GET /api/sellers — list all sellers */
    public function index(): void
    {
        $sql = 'SELECT s.*, COUNT(p.id) AS product_count
                FROM sellers s
                LEFT JOIN products p ON p.seller_id = s.id AND p.status = "active"
                GROUP BY s.id
                ORDER BY s.name';

        $stmt = $this->db->query($sql);
        $sellers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        \Flight::json(['data' => $sellers]);
    }

    /** GET /api/sellers/@id — single seller + their products */
    public function show(string $id): void
    {
        $stmt = $this->db->prepare('SELECT * FROM sellers WHERE id = ?');
        $stmt->execute([$id]);
        $seller = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$seller) {
            \Flight::json(['error' => 'Seller not found'], 404);
            return;
        }

        // Fetch their products
        $prodStmt = $this->db->prepare(
            'SELECT * FROM products WHERE seller_id = ? AND status = "active" ORDER BY created_at DESC'
        );
        $prodStmt->execute([$id]);
        $seller['products'] = $prodStmt->fetchAll(\PDO::FETCH_ASSOC);

        \Flight::json(['data' => $seller]);
    }
}
