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

    /**
     * Normalise an incoming image payload to a clean ordered list of URLs.
     * Accepts `images: []` (preferred) or the legacy single `image` string.
     *
     * @return array<int,string>
     */
    private function imageUrls(array $data): array
    {
        $urls = [];
        if (is_array($data['images'] ?? null)) {
            foreach ($data['images'] as $u) {
                if (!is_string($u)) continue;
                $u = trim($u);
                if ($u !== '') $urls[] = $u;
            }
        }
        if ($urls === []) {
            $single = trim((string) ($data['image'] ?? ''));
            if ($single !== '') $urls[] = $single;
        }
        return array_values(array_unique($urls));
    }

    /** Replace a listing's gallery and keep products.image pointing at the first photo. */
    private function replaceImages(int $productId, array $urls): void
    {
        $this->db->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);

        if ($urls !== []) {
            $ins = $this->db->prepare('INSERT INTO product_images (product_id, url, position) VALUES (?, ?, ?)');
            foreach ($urls as $i => $url) {
                $ins->execute([$productId, $url, $i]);
            }
        }

        // products.image remains the listing thumbnail used by cards and search
        $this->db->prepare('UPDATE products SET image = ? WHERE id = ?')
            ->execute([$urls[0] ?? null, $productId]);
    }

    /** @return array<int,string> */
    private function imagesFor(int $productId): array
    {
        $stmt = $this->db->prepare('SELECT url FROM product_images WHERE product_id = ? ORDER BY position, id');
        $stmt->execute([$productId]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Attach the full `images` gallery to a product row.
     * Listings created before the gallery existed fall back to the single image column.
     */
    private function withImages(array $product): array
    {
        $images = $this->imagesFor((int) $product['id']);
        if ($images === [] && !empty($product['image'])) {
            $images = [(string) $product['image']];
        }
        $product['images'] = $images;
        if ($images !== []) {
            $product['image'] = $images[0];
        }
        return $product;
    }

    /**
     * Shared WHERE clause for list queries.
     *
     * The data query and the COUNT query MUST use the same filters — they used
     * to be written separately (and drifted), which made `total`/`pages` report
     * the whole catalogue whenever a search term or price range was applied.
     *
     * @return array{0:string,1:array<int,mixed>} [whereSql, params]
     */
    private function listFilters(): array
    {
        $where  = ['p.status = ?'];
        $params = ['active'];

        if (!empty($_GET['category'])) {
            $where[]  = 'p.category = ?';
            $params[] = $_GET['category'];
        }

        if (isset($_GET['price_min']) && $_GET['price_min'] !== '') {
            $where[]  = 'p.price >= ?';
            $params[] = (float) $_GET['price_min'];
        }
        if (isset($_GET['price_max']) && $_GET['price_max'] !== '') {
            $where[]  = 'p.price <= ?';
            $params[] = (float) $_GET['price_max'];
        }

        if (isset($_GET['q']) && trim((string) $_GET['q']) !== '') {
            $where[]  = '(p.name LIKE ? OR p.description LIKE ?)';
            $term     = '%' . trim((string) $_GET['q']) . '%';
            $params[] = $term;
            $params[] = $term;
        }

        if (isset($_GET['condition']) && trim((string) $_GET['condition']) !== '') {
            $where[]  = 'p.`condition` = ?';
            $params[] = trim((string) $_GET['condition']);
        }

        if (isset($_GET['min_rating']) && $_GET['min_rating'] !== '') {
            $where[]  = 'p.rating >= ?';
            $params[] = (float) $_GET['min_rating'];
        }

        // Only promoted / only non-promoted (used by the deals rails)
        if (isset($_GET['promoted']) && $_GET['promoted'] !== '') {
            $where[]  = 'p.promoted = ?';
            $params[] = (int) (bool) $_GET['promoted'];
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    /** GET /api/products/conditions — the condition values sellers can pick */
    public function conditions(): void
    {
        \Flight::json(['data' => ['Brand New', 'Used - Like New', 'Used - Good', 'Used - Fair']]);
    }

    /** GET /api/products — list all products */
    public function index(): void
    {
        [$where, $params] = $this->listFilters();

        $sql = 'SELECT p.*, s.name AS seller_name, s.location AS seller_location, s.verified AS seller_verified, s.phone AS seller_phone
                FROM products p
                LEFT JOIN sellers s ON s.id = p.seller_id
                ' . $where;

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

        // Total count for pagination — same filters as above
        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM products p ' . $where);
        $countStmt->execute($params);
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

        \Flight::json(['data' => $this->withImages($product)]);
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
        $products = array_map(fn (array $p): array => $this->withImages($p), $stmt->fetchAll(\PDO::FETCH_ASSOC));

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
        $urls = $this->imageUrls($data);
        $stmt->execute([
            $sellerId,
            $name,
            trim((string) ($data['description'] ?? '')) ?: null,
            (float) $price,
            $urls[0] ?? null,
            $category,
            trim((string) ($data['condition'] ?? 'Brand New')) ?: 'Brand New',
        ]);

        $id = (int) $this->db->lastInsertId();
        if ($urls !== []) {
            $this->replaceImages($id, $urls);
            // replaceImages already set the thumbnail; skip the redundant UPDATE
        }

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        \Flight::json(['data' => $this->withImages($stmt->fetch(\PDO::FETCH_ASSOC))], 201);
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
        // A gallery replacement on its own is a valid update
        $hasImages = is_array($data['images'] ?? null);

        $allowed = ['name', 'description', 'price', 'image', 'category', 'condition', 'status'];
        $fields = [];
        $params = [];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) continue;
            // `image` is derived from the gallery when images are supplied
            if ($col === 'image' && $hasImages) continue;
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
        if (!$fields && !$hasImages) {
            \Flight::json(['error' => 'Nothing to update'], 422);
            return;
        }

        if ($fields) {
            $params[] = $id;
            $this->db->prepare('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($params);
        }

        if ($hasImages) {
            $this->replaceImages((int) $id, $this->imageUrls($data));
        }

        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        \Flight::json(['data' => $this->withImages($stmt->fetch(\PDO::FETCH_ASSOC))]);
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
