<?php
/**
 * Request schemas for listings.
 *
 * The detail fields are declared once and shared by the public create form,
 * the admin create form and both update paths. Keeping four near-identical
 * schemas in step by hand is how the admin form lost its Owner and Status
 * fields in the original app — one schema was updated and the others were
 * not, and the request kept succeeding.
 */
declare(strict_types=1);

namespace App\Validators;

use App\Models\Property;
use App\Support\ObjectRule;
use App\Support\Rule;
use App\Support\Validate;

final class PropertySchema
{
    /** Optional number that also accepts null, so a cleared field saves. */
    private static function optNum(): Rule
    {
        return Rule::number()->nonNegative()->nullish();
    }

    private static function dimension(): Rule
    {
        return Rule::number()->int()->positive()->max(8192)->optional();
    }

    /**
     * Rendition descriptors.
     *
     * Produced by the upload endpoint and handed straight back by the form,
     * so they are not user input in any meaningful sense — but they arrive
     * over the wire like everything else, and are bounded here rather than
     * trusted.
     */
    private static function variants(): Rule
    {
        return Validate::arrayOf(Validate::object([
            'w' => Rule::number()->int()->positive()->max(8192),
            'h' => Rule::number()->int()->positive()->max(8192)->optional(),
            'url' => Rule::string()->trim()->min(1)->max(500),
            'size' => Rule::number()->int()->nonNegative()->optional(),
        ]))->maxItems(8)->optional();
    }

    /**
     * The inline blur placeholder.
     *
     * Restricted to a base64 image data URI because this string is written
     * straight into a style attribute on the page. Any other scheme there —
     * `javascript:` above all — would be an injection point dressed up as a
     * picture.
     */
    private static function blur(): Rule
    {
        return Rule::string()
            ->trim()
            ->max(4000)
            ->check(
                static fn($v) => $v === '' || preg_match('#^data:image/(webp|png|jpeg);base64,[A-Za-z0-9+/=]+$#', (string) $v) === 1,
                'Placeholder must be a base64 image data URI'
            )
            ->optional();
    }

    private static function image(): ObjectRule
    {
        return Validate::object([
            'url' => Rule::string()->trim()->min(1, 'Image url is required'),
            'publicId' => Rule::string()->trim()->max(64)->optional(),
            'caption' => Rule::string()->trim()->max(200)->optional(),
            'isPrimary' => Rule::boolean()->optional(),
            'variants' => self::variants(),
            'blur' => self::blur(),
            'width' => self::dimension(),
            'height' => self::dimension(),
            'contentType' => Rule::string()->trim()->max(40)->optional(),
            'size' => Rule::number()->int()->nonNegative()->optional(),
            'originalName' => Rule::string()->trim()->max(260)->optional(),
        ]);
    }

    /**
     * Everything under "Property Specifications" on the detail page.
     *
     * All optional: a small shed has no column grid and a plot has no clear
     * height. Requiring them would force whoever is listing to invent a
     * number, which is how that page ended up showing the same nine
     * specifications for every property in the first place.
     */
    private static function specs(): Rule
    {
        return Validate::object([
            'clearHeight' => self::optNum(),
            'loadingDocks' => Rule::number()->int()->nonNegative()->nullish(),
            'power' => self::optNum(),
            'floorStrength' => self::optNum(),
            'officeArea' => self::optNum(),
            'columnSpacing' => Rule::string()->trim()->max(40)->nullish(),
            'truckTurning' => self::optNum(),
            'fireSystem' => Rule::string()->trim()->max(80)->nullish(),
            'features' => Validate::arrayOf(Rule::string()->trim()->max(80))->maxItems(30)->nullish(),
        ])->optional();
    }

    /**
     * Fields shared by every create and update path.
     *
     * @return array<string,Rule>
     */
    private static function detailFields(): array
    {
        return [
            'specs' => self::specs(),
            'images' => Validate::arrayOf(self::image())->maxItems(12)->optional(),
            'floorPlan' => Validate::object([
                'url' => Rule::string()->trim()->min(1)->optional(),
                'publicId' => Rule::string()->trim()->max(64)->optional(),
                'variants' => self::variants(),
                'blur' => self::blur(),
                'width' => self::dimension(),
                'height' => self::dimension(),
                'contentType' => Rule::string()->trim()->max(40)->optional(),
                'size' => Rule::number()->int()->nonNegative()->optional(),
                'originalName' => Rule::string()->trim()->max(260)->optional(),
            ])->nullish(),
            'distances' => Validate::arrayOf(Validate::object([
                'label' => Rule::string()->trim()->min(1)->max(60),
                'km' => Rule::number()->nonNegative(),
            ]))->maxItems(8)->optional(),
            'availableFrom' => Rule::date()->nullish(),
            'address' => Rule::string()->trim()->max(300)->nullish(),
            'pincode' => Rule::string()->trim()->max(10)->nullish(),
            /* Rendered as a link on the detail page, so the scheme is
               checked — an empty value passes, which is how it is cleared. */
            'mapsUrl' => Rule::string()
                ->trim()
                ->max(500)
                ->check(
                    static fn($v) => $v === '' || preg_match('#^https?://#i', (string) $v) === 1,
                    'Map link must start with http:// or https://'
                )
                ->nullish(),
        ];
    }

    /**
     * What anyone may submit.
     *
     * No ownerName, no status, no isVerified: a submitter must not be able
     * to name a different owner or approve their own listing. Because the
     * schema is strict, sending one is a loud 422 rather than a silent strip.
     *
     * @return array<string,Rule>
     */
    private static function createFields(): array
    {
        return array_merge([
            'name' => Rule::string()->trim()->min(3, 'Title must be at least 3 characters')->max(160),
            'description' => Rule::string()->trim()->max(5000)->optional(),
            'type' => Rule::enum(Property::TYPES)->optional(),
            'grade' => Rule::enum(Property::GRADES)->optional(),
            'city' => Rule::string()->trim()->min(1, 'City is required')->max(80),
            'locality' => Rule::string()->trim()->max(120)->optional(),
            'rate' => Rule::number()->positive('Rate must be greater than zero'),
            'area' => Rule::number()->positive('Area must be greater than zero'),
            'depositMonths' => Rule::number()->min(0)->max(24)->optional(),
        ], self::detailFields());
    }

    public static function create(): ObjectRule
    {
        return Validate::object(self::createFields())
            ->requires('name', 'Property title is required')
            ->requires('city', 'City is required')
            ->requires('rate', 'Rate is required')
            ->requires('area', 'Area is required');
    }

    /** Admins get three more fields: they moderate, and they enter listings
     *  on behalf of real owners. */
    public static function adminCreate(): ObjectRule
    {
        return Validate::object(array_merge(self::createFields(), [
            'ownerName' => Rule::string()->trim()->max(160)->optional(),
            'status' => Rule::enum(Property::STATUSES)->optional(),
            'isVerified' => Rule::boolean()->optional(),
        ]))->requires('name', 'Property title is required')
           ->requires('city', 'City is required')
           ->requires('rate', 'Rate is required')
           ->requires('area', 'Area is required');
    }

    /** @return array<string,Rule> */
    private static function updateFields(): array
    {
        return array_merge([
            'name' => Rule::string()->trim()->min(3)->max(160)->optional(),
            'description' => Rule::string()->trim()->max(5000)->optional(),
            'type' => Rule::enum(Property::TYPES)->optional(),
            'grade' => Rule::enum(Property::GRADES)->optional(),
            'city' => Rule::string()->trim()->min(1)->max(80)->optional(),
            'locality' => Rule::string()->trim()->max(120)->optional(),
            'rate' => Rule::number()->positive()->optional(),
            'area' => Rule::number()->positive()->optional(),
            'depositMonths' => Rule::number()->min(0)->max(24)->optional(),
        ], self::detailFields());
    }

    public static function update(): ObjectRule
    {
        return Validate::object(self::updateFields())->nonEmpty();
    }

    public static function adminUpdate(): ObjectRule
    {
        return Validate::object(array_merge(self::updateFields(), [
            'status' => Rule::enum(Property::STATUSES)->optional(),
            'isVerified' => Rule::boolean()->optional(),
            /* The admin form posts every field it renders, ownerName among
               them, so the schema has to accept it — otherwise strict mode
               rejects the whole request and no edit saves at all. */
            'ownerName' => Rule::string()->trim()->max(160)->optional(),
        ]));
    }

    public static function reject(): ObjectRule
    {
        return Validate::object([
            'reason' => Rule::string()->trim()->min(1, 'A rejection reason is required')->max(500),
        ])->requires('reason', 'A rejection reason is required');
    }

    public static function listQuery(): ObjectRule
    {
        return Validate::object([
            'page' => Rule::number()->int()->positive()->optional(),
            'limit' => Rule::number()->int()->positive()->max(100)->optional(),
            'city' => Rule::string()->trim()->max(80)->optional(),
            'type' => Rule::enum(Property::TYPES)->optional(),
            'grade' => Rule::enum(Property::GRADES)->optional(),
            'status' => Rule::enum(Property::STATUSES)->optional(),
            'minRate' => Rule::number()->nonNegative()->optional(),
            'maxRate' => Rule::number()->nonNegative()->optional(),
            'minArea' => Rule::number()->nonNegative()->optional(),
            'maxArea' => Rule::number()->nonNegative()->optional(),
            'q' => Rule::string()->trim()->max(120)->optional(),
            'sort' => Rule::enum(['rate-asc', 'rate-desc', 'area-asc', 'area-desc', 'newest', 'oldest'])->optional(),
        /* Loose: the pages append cache-busting and UI-state parameters to
           these URLs, and a strict query schema would 422 the whole feed
           over a stray `_=1699…`. Unknown keys are dropped, and every key
           that reaches a query is named explicitly above. */
        ])->loose();
    }
}
