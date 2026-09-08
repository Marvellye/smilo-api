<?php

declare(strict_types=1);

namespace Smilo\Controller;

class ReviewController
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /** GET /api/products/@id/reviews — list reviews for a product */
    public function index(string $productId): void
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM reviews WHERE product_id = ? ORDER BY helpful DESC, created_at DESC'
        );
        $stmt->execute([$productId]);
        $reviews = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Rating summary
        $stmt2 = $this->db->prepare(
            'SELECT rating, COUNT(*) as count FROM reviews WHERE product_id = ? GROUP BY rating ORDER BY rating DESC'
        );
        $stmt2->execute([$productId]);
        $ratingBreakdown = $stmt2->fetchAll(\PDO::FETCH_ASSOC);

        \Flight::json([
            'data'     => $reviews,
            'breakdown'=> $ratingBreakdown,
        ]);
    }

    /** POST /api/products/@id/reviews — submit a review */
    public function create(string $productId): void
    {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['author']) || empty($data['rating'])) {
            \Flight::json(['error' => 'author and rating are required'], 422);
            return;
        }

        $rating = max(1, min(5, (int) $data['rating']));

        $stmt = $this->db->prepare(
            'INSERT INTO reviews (product_id, user_id, author, rating, title, body) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $productId,
            $data['user_id'] ?? null,
            $data['author'],
            $rating,
            $data['title'] ?? null,
            $data['body'] ?? null,
        ]);

        $reviewId = (int) $this->db->lastInsertId();

        // Update product rating aggregate
        $this->updateProductRating($productId);

        \Flight::json([
            'id'      => $reviewId,
            'message' => 'Review submitted',
        ], 201);
    }

    /** POST /api/reviews/@id/helpful — increment helpful count */
    public function helpful(string $id): void
    {
        $stmt = $this->db->prepare('UPDATE reviews SET helpful = helpful + 1 WHERE id = ?');
        $stmt->execute([$id]);
        \Flight::json(['message' => 'Marked helpful']);
    }

    private function updateProductRating(string $productId): void
    {
        $stmt = $this->db->prepare(
            'SELECT AVG(rating) as avg_rating, COUNT(*) as cnt FROM reviews WHERE product_id = ?'
        );
        $stmt->execute([$productId]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($result) {
            $this->db->prepare('UPDATE products SET rating = ?, review_count = ? WHERE id = ?')
                ->execute([round($result['avg_rating'], 1), $result['cnt'], $productId]);
        }
    }
}
