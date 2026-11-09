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

    public const EMPLOYMENT_TYPES = ['full-time', 'freelance', 'apprenticeship'];

    public const LANGUAGE_SKILLS = ['reading', 'writing', 'listening', 'speaking'];

    /** The levels of the Common European Framework of Reference for Languages. */
    public const LANGUAGE_LEVELS = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

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
                    new Field('employment_type', 'Kind of work', FieldType::Enum, options: self::EMPLOYMENT_TYPES),
                    new Field('url', 'Link', FieldType::Url),
                    new Field('summary', 'What', FieldType::Text),
                    self::keywordGroups('skills', 'Skills'),
                ]),
                new Field('education', 'Education', FieldType::List, translatable: true, of: [
                    new Field('institution', 'Institution', FieldType::String, required: true),
                    new Field('study_type', 'Kind of study', FieldType::String, required: true),
                    new Field('area', 'Area', FieldType::String, required: true),
                    new Field('start_date', 'From', FieldType::String, required: true, help: 'YYYY, YYYY-MM or YYYY-MM-DD.'),
                    new Field('end_date', 'To', FieldType::String, help: 'Empty = still there.'),
                    self::keywordGroups('skills', 'Skills'),
                ]),
                new Field('languages', 'Languages', FieldType::List, translatable: true, of: [
                    new Field('language', 'Language', FieldType::String, required: true),
                    new Field('fluency', 'Fluency', FieldType::String, help: 'In words, such as "Native" or "B1".'),
                    new Field('levels', 'Levels', FieldType::List, of: [
                        new Field('skill', 'Skill', FieldType::Enum, required: true, options: self::LANGUAGE_SKILLS),
                        new Field('level', 'Level', FieldType::Enum, required: true, options: self::LANGUAGE_LEVELS),
                    ]),
                ]),
                self::keywordGroups('interests', 'Interests', translatable: true),
                new Field('pdf', 'PDF', FieldType::Media),
            ],
        );
    }

    /** A list of named groups of keywords, the shape of skills and interests alike. */
    private static function keywordGroups(string $key, string $label, bool $translatable = false): Field
    {
        return new Field($key, $label, FieldType::List, translatable: $translatable, of: [
            new Field('name', 'Name', FieldType::String, required: true),
            new Field('keywords', 'Keywords', FieldType::String, multiple: true),
        ]);
    }
}
