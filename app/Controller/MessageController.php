<?php

declare(strict_types=1);

namespace Smilo\Controller;

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

        $name  = $data['name'] ?? 'Anonymous';
        $email = $data['email'] ?? null;
        $phone = $data['phone'] ?? null;
        $body  = $data['body'];

        $stmt = $this->db->prepare(
            'INSERT INTO messages (sender_id, product_id, seller_id, name, email, phone, body) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            null, // sender_id — set if authenticated
            $data['product_id'],
            $product['seller_id'],
            $name,
            $email,
            $phone,
            $body,
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
            'SELECT * FROM messages WHERE product_id = ? ORDER BY created_at DESC'
        );
        $stmt->execute([$productId]);
        $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        \Flight::json(['data' => $messages]);
    }

    /** PUT /api/messages/@id/read — mark message as read */
    public function markRead(string $id): void
    {
        $stmt = $this->db->prepare('UPDATE messages SET is_read = 1 WHERE id = ?');
        $stmt->execute([$id]);

        \Flight::json(['message' => 'Marked as read']);
    }
}
