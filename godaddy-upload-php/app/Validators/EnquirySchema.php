<?php
/**
 * Request schemas for enquiries.
 */
declare(strict_types=1);

namespace App\Validators;

use App\Models\Enquiry;
use App\Support\ObjectRule;
use App\Support\Rule;
use App\Support\Validate;

final class EnquirySchema
{
    /**
     * The contact form.
     *
     * Strict, and `status` is absent by design — a sender must not be able
     * to file their own enquiry as "closed". `user` is absent for the same
     * reason: it is taken from the session, never from the body.
     */
    public static function create(): ObjectRule
    {
        return Validate::object([
            'name' => Rule::string()->trim()->min(2, 'Please enter your name')->max(120),
            'email' => Rule::string()->trim()->lower()->email('Please enter a valid email address'),
            'mobile' => Rule::string()->trim()->max(20)->optional(),
            'company' => Rule::string()->trim()->max(160)->optional(),
            'subject' => Rule::string()->trim()->max(160)->optional(),
            'message' => Rule::string()->trim()->min(5, 'Please tell us how we can help')->max(4000),
            'property' => Rule::objectId()->optional(),
        ])->requires('name', 'Please enter your name')
          ->requires('email', 'Email is required')
          ->requires('message', 'Please tell us how we can help');
    }

    public static function updateStatus(): ObjectRule
    {
        return Validate::object([
            'status' => Rule::enum(Enquiry::STATUSES)->optional(),
            'adminNote' => Rule::string()->trim()->max(2000)->optional(),
        ])->nonEmpty();
    }

    public static function listQuery(): ObjectRule
    {
        return Validate::object([
            'page' => Rule::number()->int()->positive()->optional(),
            'limit' => Rule::number()->int()->positive()->max(100)->optional(),
            'status' => Rule::enum(Enquiry::STATUSES)->optional(),
            'q' => Rule::string()->trim()->max(120)->optional(),
        ])->loose();
    }
}
