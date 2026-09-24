<?php

declare(strict_types=1);

namespace Smilo\Controller;

use Smilo\Helper\Auth;

/**
 * Saved ads (wishlist).
 *
 * The frontend hearts on product cards and the product page used to be
 * cosmetic — this is the persistence behind them.
 */
class FavoriteController
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    private function userId(): ?int
    {
        $payload = Auth::getUserFromRequest();
        return $payload ? (int) $payload['sub'] : null;
    }

    /** GET /api/favorites — the current user's saved ads, newest first */
    public function index(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT p.*, s.name AS seller_name, s.location AS seller_location,
                    s.verified AS seller_verified, s.phone AS seller_phone,
                    f.created_at AS saved_at
             FROM favorites f
             JOIN products p ON p.id = f.product_id
             LEFT JOIN sellers s ON s.id = p.seller_id
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC, f.id DESC'
        );
        $stmt->execute([$userId]);

        \Flight::json(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    /**
     * GET /api/favorites/ids — just the saved product ids.
     *
     * Lets the client hydrate every heart on the page from one cheap call.
     * Returns an empty list (not 401) for guests so the UI can render.
     */
    public function ids(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            \Flight::json(['data' => []]);
            return;
        }

        $stmt = $this->db->prepare('SELECT product_id FROM favorites WHERE user_id = ?');
        $stmt->execute([$userId]);
        $ids = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));

        \Flight::json(['data' => $ids]);
    }

    /** POST /api/favorites {product_id} — toggle saved state */
    public function toggle(): void
    {
        $userId = $this->userId();
        if (!$userId) {
            \Flight::json(['error' => 'Please sign in to save ads'], 401);
            return;
        }

        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $productId = (int) ($data['product_id'] ?? 0);
        if ($productId <= 0) {
            \Flight::json(['error' => 'product_id is required'], 422);
            return;
        }

        // Only real, active ads can be saved
        $chk = $this->db->prepare("SELECT id FROM products WHERE id = ? AND status = 'active'");
        $chk->execute([$productId]);
        if ($chk->fetchColumn() === false) {
            \Flight::json(['error' => 'Product not found'], 404);
            return;
        }

        $stmt = $this->db->prepare('SELECT id FROM favorites WHERE user_id = ? AND product_id = ?');
        $stmt->execute([$userId, $productId]);
        $existing = $stmt->fetchColumn();

        if ($existing !== false) {
            $this->db->prepare('DELETE FROM favorites WHERE id = ?')->execute([(int) $existing]);
            \Flight::json(['data' => ['product_id' => $productId, 'saved' => false]]);
            return;
        }

        $this->db->prepare('INSERT INTO favorites (user_id, product_id) VALUES (?, ?)')
            ->execute([$userId, $productId]);

        \Flight::json(['data' => ['product_id' => $productId, 'saved' => true]], 201);
    }

    /** DELETE /api/favorites/@productId — unsave */
    public function destroy(string $productId): void
    {
        $userId = $this->userId();
        if (!$userId) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $this->db->prepare('DELETE FROM favorites WHERE user_id = ? AND product_id = ?')
            ->execute([$userId, (int) $productId]);

        \Flight::json(['data' => ['product_id' => (int) $productId, 'saved' => false]]);
    }
}
