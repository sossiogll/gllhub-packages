<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Types;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;

/** An article, written here or brought in from elsewhere (Medium…). */
final class Article implements ContentType
{
    public const KEY = 'article';

    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            key: self::KEY,
            label: 'Article',
            pluralLabel: 'Articles',
            fields: [
                new Field('origin_url', 'Published at', FieldType::Url, help: 'Where it was first published, when not here.'),
                new Field('reading_minutes', 'Reading time (minutes)', FieldType::Integer),
                new Field('cover', 'Cover', FieldType::Media),
                new Field('tags', 'Tags', FieldType::String, multiple: true),
                new Field('project', 'About the project', FieldType::Reference, curated: true, references: [Project::KEY]),
                ...CuratedFields::listing(),
            ],
            inFeed: true,
            notifiesSubscribers: true,
            routePrefix: 'writing',
        );
    }
}
