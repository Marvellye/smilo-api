<?php

declare(strict_types=1);

namespace Smilo\Controller;

use Smilo\Helper\Auth;

class MessageController
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /** POST /api/messages — send a message to a seller about a product */
    public function send(): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['product_id']) || empty($data['body'])) {
            \Flight::json(['error' => 'product_id and body are required'], 422);
            return;
        }

        // Look up the product to get the seller_id
        $stmt = $this->db->prepare('SELECT seller_id FROM products WHERE id = ?');
        $stmt->execute([$data['product_id']]);
        $product = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$product) {
            \Flight::json(['error' => 'Product not found'], 404);
            return;
        }

        // Attribute to the logged-in user when a token is present
        $payload = Auth::getUserFromRequest();
        $senderId = $payload ? (int) $payload['sub'] : null;
        $name  = $data['name'] ?? 'Anonymous';
        $email = $data['email'] ?? ($payload['email'] ?? null);
        $phone = $data['phone'] ?? null;
        $body  = trim((string) $data['body']);
        if ($body === '') {
            \Flight::json(['error' => 'Message body is required'], 422);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO messages (sender_id, product_id, seller_id, name, email, phone, body, image) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $senderId,
            $data['product_id'],
            $product['seller_id'],
            $name,
            $email,
            $phone,
            $body,
            isset($data['image']) && trim((string) $data['image']) !== '' ? trim((string) $data['image']) : null,
        ]);

        $msgId = (int) $this->db->lastInsertId();

        \Flight::json([
            'id'      => $msgId,
            'message' => 'Message sent successfully',
        ], 201);
    }

    /** GET /api/messages?product_id=X — list messages for a product (seller only) */
    public function index(): void
    {
        $productId = $_GET['product_id'] ?? null;
        if (!$productId) {
            \Flight::json(['error' => 'product_id is required'], 422);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT m.*, p.name AS product_name, p.image AS product_image
             FROM messages m LEFT JOIN products p ON p.id = m.product_id
             WHERE m.product_id = ? ORDER BY m.created_at DESC'
        );
        $stmt->execute([$productId]);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        \Flight::json(['data' => $messages]);
    }

    /** GET /api/messages/inbox — messages received on the seller's listings */
    public function inbox(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $stmt = $this->db->prepare('SELECT id FROM sellers WHERE user_id = ?');
        $stmt->execute([(int) $payload['sub']]);
        $sellerIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (!$sellerIds) {
            \Flight::json(['data' => [], 'unread' => 0]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($sellerIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT m.*, p.name AS product_name, p.image AS product_image, p.price AS product_price
             FROM messages m LEFT JOIN products p ON p.id = m.product_id
             WHERE m.seller_id IN ($placeholders) ORDER BY m.created_at DESC LIMIT 100"
        );
        $stmt->execute($sellerIds);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $unread = 0;
        foreach ($messages as $m) {
            if (!(int) ($m['is_read'] ?? 0)) $unread++;
        }

        \Flight::json(['data' => $messages, 'unread' => $unread]);
    }

    /** GET /api/messages/sent — messages the current user has sent */
    public function sent(): void
    {
        $payload = Auth::getUserFromRequest();
        if (!$payload) {
            \Flight::json(['error' => 'Unauthenticated'], 401);
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT m.*, p.name AS product_name, p.image AS product_image
             FROM messages m LEFT JOIN products p ON p.id = m.product_id
             WHERE m.sender_id = ? ORDER BY m.created_at DESC LIMIT 100'
        );
        $stmt->execute([(int) $payload['sub']]);
        \Flight::json(['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)]);
    }

    /** PUT /api/messages/@id/read — mark message as read */
    public function markRead(string $id): void
    {
        $stmt = $this->db->prepare('UPDATE messages SET is_read = 1 WHERE id = ?');
        $stmt->execute([$id]);

        \Flight::json(['message' => 'Marked as read']);
    }
}
