<?php
/**
 * Request schemas for reviews.
 */
declare(strict_types=1);

namespace App\Validators;

use App\Models\Review;
use App\Support\ObjectRule;
use App\Support\Rule;
use App\Support\Validate;

final class ReviewSchema
{
    /**
     * Writing a review.
     *
     * `authorName` is not accepted: it is taken from the session, or anyone
     * could post under someone else's name — which is exactly the problem
     * the hard-coded testimonials had. `status` is not accepted either, so
     * nobody can publish their own review. Strict, so an attempt at either
     * is a loud 422 rather than a silent strip.
     */
    public static function create(): ObjectRule
    {
        return Validate::object([
            'rating' => Rule::number()->int()->min(1, 'Rating must be 1–5')->max(5, 'Rating must be 1–5'),
            'comment' => Rule::string()
                ->trim()
                ->min(10, 'Please write at least 10 characters')
                ->max(1500, 'Reviews are limited to 1500 characters'),
            'authorRole' => Rule::string()->trim()->max(120)->optional(),
        ])->requires('rating', 'A rating is required')
          ->requires('comment', 'Please write a few words');
    }

    public static function moderate(): ObjectRule
    {
        return Validate::object([
            'status' => Rule::enum(Review::STATUSES),
            'rejectionReason' => Rule::string()->trim()->max(500)->optional(),
        ])->requires('status', 'A status is required')
          /* A rejection without a reason leaves the admin log with no record
             of why, and the reviewer with nothing to act on. */
          ->refine(
              static fn(array $v) => ($v['status'] ?? '') !== 'rejected' || !empty($v['rejectionReason']),
              'A reason is required when rejecting a review',
              'rejectionReason'
          );
    }

    public static function listQuery(): ObjectRule
    {
        return Validate::object([
            'page' => Rule::number()->int()->positive()->optional(),
            'limit' => Rule::number()->int()->positive()->max(50)->optional(),
        ])->loose();
    }
}
