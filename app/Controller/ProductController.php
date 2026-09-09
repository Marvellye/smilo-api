<?php

declare(strict_types=1);

namespace Smilo\Controller;

use flight\Engine;
use Smilo\Helper\Auth;

class ProductController
{
    private Engine $app;
    private \PDO $db;

    public function __construct(Engine $app, \PDO $db)
    {
        $this->app = $app;
        $this->db  = $db;
    }

    /** Resolve the sellers.id owned by the authenticated user (or null). */
    private function mySellerId(int $userId): ?int
    {
        $stmt = $this->db->prepare('SELECT id FROM sellers WHERE user_id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** GET /api/products — list all products */
    public function index(): void
    {
        $sql = 'SELECT p.*, s.name AS seller_name, s.location AS seller_location, s.verified AS seller_verified, s.phone AS seller_phone
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
            'SELECT p.*, s.name AS seller_name, s.location AS seller_location, s.verified AS seller_verified, s.phone AS seller_phone
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

    /** GET /api/products/mine — authenticated seller's own listings (all statuses) */
    public function mine(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }
        $sellerId = $this->mySellerId((int) $payload['sub']);
        if ($sellerId === null) {
            \Flight::json(['data' => [], 'total' => 0]);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT p.*, s.name AS seller_name, s.location AS seller_location, s.verified AS seller_verified, s.phone AS seller_phone
             FROM products p LEFT JOIN sellers s ON s.id = p.seller_id
             WHERE p.seller_id = ? AND p.status != ? ORDER BY p.created_at DESC'
        );
        $stmt->execute([$sellerId, 'removed']);
        $products = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        \Flight::json(['data' => $products, 'total' => count($products)]);
    }

    /** POST /api/products — create a listing for the authenticated seller */
    public function store(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }
        $sellerId = $this->mySellerId((int) $payload['sub']);
        if ($sellerId === null) {
            \Flight::json(['error' => 'Become a seller before posting an ad'], 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string) ($data['name'] ?? ''));
        $price = $data['price'] ?? null;
        $category = trim((string) ($data['category'] ?? ''));
        if ($name === '' || $category === '' || !is_numeric($price) || (float) $price <= 0) {
            \Flight::json(['error' => 'Name, a positive price, and category are required'], 422);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO products (seller_id, name, description, price, image, category, `condition`)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sellerId,
            $name,
            trim((string) ($data['description'] ?? '')) ?: null,
            (float) $price,
            trim((string) ($data['image'] ?? '')) ?: null,
            $category,
            trim((string) ($data['condition'] ?? 'Brand New')) ?: 'Brand New',
        ]);

        $id = (int) $this->db->lastInsertId();
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        \Flight::json(['data' => $stmt->fetch(\PDO::FETCH_ASSOC)], 201);
    }

    /** PUT /api/products/@id — update own listing */
    public function update(string $id): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }
        $sellerId = $this->mySellerId((int) $payload['sub']);

        $stmt = $this->db->prepare('SELECT seller_id FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            \Flight::json(['error' => 'Product not found'], 404);
            return;
        }
        if ($sellerId === null || (int) $row['seller_id'] !== $sellerId) {
            \Flight::json(['error' => 'Not your listing'], 403);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $allowed = ['name', 'description', 'price', 'image', 'category', 'condition', 'status'];
        $fields = [];
        $params = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) continue;
            if ($col === 'price') {
                if (!is_numeric($data['price']) || (float) $data['price'] <= 0) {
                    \Flight::json(['error' => 'Price must be positive'], 422);
                    return;
                }
                $fields[] = 'price = ?';
                $params[] = (float) $data['price'];
            } elseif ($col === 'status') {
                if (!in_array($data['status'], ['active', 'sold', 'removed'], true)) {
                    \Flight::json(['error' => 'Invalid status'], 422);
                    return;
                }
                $fields[] = 'status = ?';
                $params[] = $data['status'];
            } else {
                $fields[] = "`$col` = ?";
                $params[] = $data[$col] ?: null;
            }
        }
        if (!$fields) {
            \Flight::json(['error' => 'Nothing to update'], 422);
            return;
        }

        $params[] = $id;
        $this->db->prepare('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        \Flight::json(['data' => $stmt->fetch(\PDO::FETCH_ASSOC)]);
    }

    /** DELETE /api/products/@id — remove own listing (soft delete) */
    public function destroy(string $id): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }
        $sellerId = $this->mySellerId((int) $payload['sub']);

        $stmt = $this->db->prepare('SELECT seller_id FROM products WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            \Flight::json(['error' => 'Product not found'], 404);
            return;
        }
        if ($sellerId === null || (int) $row['seller_id'] !== $sellerId) {
            \Flight::json(['error' => 'Not your listing'], 403);
            return;
        }

        $this->db->prepare("UPDATE products SET status = 'removed' WHERE id = ?")->execute([$id]);
        \Flight::json(['message' => 'Listing removed']);
    }
}
