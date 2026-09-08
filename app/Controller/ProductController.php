<?php

declare(strict_types=1);

namespace Smilo\Controller;

use flight\Engine;

class ProductController
{
    private Engine $app;
    private \PDO $db;

    public function __construct(Engine $app, \PDO $db)
    {
        $this->app = $app;
        $this->db  = $db;
    }

    /** GET /api/products — list all products */
    public function index(): void
    {
        $sql = 'SELECT p.*, s.name AS seller_name, s.location AS seller_location, s.verified AS seller_verified
                FROM products p
                LEFT JOIN sellers s ON s.id = p.seller_id
                WHERE p.status = ?';
        $params = ['active'];

        // Category filter
        if (!empty($_GET['category'])) {
            $sql .= ' AND p.category = ?';
            $params[] = $_GET['category'];
        }

        // Price range
        if (isset($_GET['price_min'])) {
            $sql .= ' AND p.price >= ?';
            $params[] = (float) $_GET['price_min'];
        }
        if (isset($_GET['price_max'])) {
            $sql .= ' AND p.price <= ?';
            $params[] = (float) $_GET['price_max'];
        }

        // Search
        if (!empty($_GET['q'])) {
            $sql .= ' AND (p.name LIKE ? OR p.description LIKE ?)';
            $term = '%' . $_GET['q'] . '%';
            $params[] = $term;
            $params[] = $term;
        }

        // Sorting
        $sort = match ($_GET['sort'] ?? 'newest') {
            'price_low'  => 'p.price ASC',
            'price_high' => 'p.price DESC',
            'rating'     => 'p.rating DESC',
            default      => 'p.created_at DESC',
        };
        $sql .= " ORDER BY p.promoted DESC, $sort";

        // Pagination
        $page  = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int) ($_GET['limit'] ?? 24)));
        $offset = ($page - 1) * $limit;
        $sql .= " LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Total count for pagination
        $countSql = 'SELECT COUNT(*) FROM products WHERE status = ?';
        $countParams = ['active'];
        if (!empty($_GET['category'])) {
            $countSql .= ' AND category = ?';
            $countParams[] = $_GET['category'];
        }
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int) $countStmt->fetchColumn();

        \Flight::json([
            'data'  => $products,
            'total' => $total,
            'page'  => $page,
            'limit' => $limit,
            'pages' => (int) ceil($total / $limit),
        ]);
    }

    /** GET /api/products/@id */
    public function show(string $id): void
    {
        $stmt = $this->db->prepare(
            'SELECT p.*, s.name AS seller_name, s.location AS seller_location, s.verified AS seller_verified
             FROM products p
             LEFT JOIN sellers s ON s.id = p.seller_id
             WHERE p.id = ?'
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$product) {
            \Flight::json(['error' => 'Product not found'], 404);
            return;
        }

        \Flight::json(['data' => $product]);
    }

    /** GET /api/categories — distinct categories */
    public function categories(): void
    {
        $stmt = $this->db->query(
            'SELECT DISTINCT category FROM products WHERE status = "active" ORDER BY category'
        );
        $cats = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        \Flight::json(['data' => $cats]);
    }
}
