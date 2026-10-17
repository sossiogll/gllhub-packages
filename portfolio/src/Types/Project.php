<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Types;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;

/** A project with its showcase. The fields mirror the front matter of showcase.md. */
final class Project implements ContentType
{
    public const KEY = 'project';

    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            key: self::KEY,
            label: 'Project',
            pluralLabel: 'Projects',
            fields: [
                new Field('claim', 'Claim', FieldType::String, required: true, translatable: true, maxLength: 160),
                new Field('color', 'Header colour', FieldType::Color),
                new Field('cover', 'Cover', FieldType::Media),
                new Field('since', 'Since (year)', FieldType::Integer),
                new Field('repository', 'Repository', FieldType::Url),
                new Field('languages', 'Languages', FieldType::String, multiple: true),
                new Field('demo', 'Demo', FieldType::Enum, options: ['playground', 'video', 'image']),
                new Field('demo_media', 'Demo media', FieldType::Media),
                new Field('milestone', 'Milestone shown as progress', FieldType::String),
                new Field('features', 'What it does', FieldType::List, translatable: true, of: [
                    new Field('title', 'Title', FieldType::String, required: true),
                    new Field('text', 'Text', FieldType::Text, required: true),
                    new Field('icon', 'Icon', FieldType::String),
                ]),
                new Field('decisions', "How it's made", FieldType::List, translatable: true, of: [
                    new Field('what', 'Choice', FieldType::String, required: true),
                    new Field('why', 'Why', FieldType::Text, required: true),
                ]),
                new Field('related', 'Related projects', FieldType::Reference, multiple: true, references: [self::KEY]),
                ...CuratedFields::listing(),
            ],
            routePrefix: 'projects',
        );
    }
}
