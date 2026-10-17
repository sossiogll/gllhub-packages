<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Types;

use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;

/** The curation every listed type shares, declared once. */
final class CuratedFields
{
    /** @return list<Field> */
    public static function listing(): array
    {
        return [
            new Field('featured', 'Featured', FieldType::Boolean, curated: true, help: 'Shown first on the home page.'),
            new Field('position', 'Position', FieldType::Integer, curated: true, help: 'Lower comes first; empty = by date.'),
            new Field('card_claim', 'Short claim for cards', FieldType::String, translatable: true, curated: true, maxLength: 120),
        ];
    }
}
