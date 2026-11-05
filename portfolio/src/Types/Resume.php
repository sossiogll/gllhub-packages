<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Types;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;

/**
 * A CV. The core's title, summary and body hold the name, the headline and the introduction. Dates are text, because a
 * CV may stop at the month or the year. Contact details have no field: what is here is public.
 */
final class Resume implements ContentType
{
    public const KEY = 'resume';

    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            key: self::KEY,
            label: 'CV',
            pluralLabel: 'CVs',
            fields: [
                new Field('work', 'Work', FieldType::List, translatable: true, of: [
                    new Field('name', 'Where', FieldType::String, required: true),
                    new Field('position', 'Position', FieldType::String, required: true),
                    new Field('start_date', 'From', FieldType::String, required: true, help: 'YYYY, YYYY-MM or YYYY-MM-DD.'),
                    new Field('end_date', 'To', FieldType::String, help: 'Empty = still there.'),
                    new Field('url', 'Link', FieldType::Url),
                    new Field('summary', 'What', FieldType::Text),
                ]),
                new Field('education', 'Education', FieldType::List, translatable: true, of: [
                    new Field('institution', 'Institution', FieldType::String, required: true),
                    new Field('study_type', 'Kind of study', FieldType::String, required: true),
                    new Field('area', 'Area', FieldType::String, required: true),
                    new Field('start_date', 'From', FieldType::String, required: true, help: 'YYYY, YYYY-MM or YYYY-MM-DD.'),
                    new Field('end_date', 'To', FieldType::String, help: 'Empty = still there.'),
                ]),
                new Field('pdf', 'PDF', FieldType::Media),
            ],
        );
    }
}
