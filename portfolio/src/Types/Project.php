<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Types;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;

/** A project: what its showcase says about it, and the facts its repository gives. */
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
                new Field('version', 'Latest version', FieldType::String),
                new Field('stars', 'Stars', FieldType::Integer),
                new Field('readme', 'README', FieldType::Markdown),
                new Field('releases', 'Releases', FieldType::List, of: [
                    new Field('tag', 'Tag', FieldType::String, required: true),
                    new Field('name', 'Name', FieldType::String),
                    new Field('url', 'Link', FieldType::Url),
                    new Field('published_at', 'Published at', FieldType::Date),
                    new Field('body', 'Notes', FieldType::Markdown),
                    new Field('prerelease', 'Pre-release', FieldType::Boolean),
                ]),
                new Field('milestones', 'Milestones', FieldType::List, of: [
                    new Field('title', 'Title', FieldType::String, required: true),
                    new Field('description', 'Description', FieldType::Text),
                    new Field('state', 'State', FieldType::Enum, options: ['open', 'closed']),
                    new Field('open_items', 'Open items', FieldType::Integer),
                    new Field('closed_items', 'Closed items', FieldType::Integer),
                    new Field('due_at', 'Due at', FieldType::Date),
                    new Field('url', 'Link', FieldType::Url),
                ]),
                new Field('images', 'Images in the text', FieldType::Media, multiple: true),
                ...CuratedFields::listing(),
            ],
        );
    }
}
