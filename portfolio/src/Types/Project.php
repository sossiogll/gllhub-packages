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
                new Field('demo', 'Demo', FieldType::Enum, options: ['widget', 'video', 'image']),
                new Field('demo_widget', 'Demo widget', FieldType::String, help: 'The name of a widget of the site; gllHub does not read it.'),
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
                    new Field('items', 'Items', FieldType::List, of: [
                        new Field('title', 'Title', FieldType::String, required: true),
                        new Field('number', 'Number', FieldType::Integer),
                        new Field('url', 'Link', FieldType::Url),
                        new Field('kind', 'Kind', FieldType::Enum, options: ['issue', 'pull_request']),
                        new Field('state', 'State', FieldType::Enum, options: ['open', 'closed', 'merged']),
                    ]),
                ]),
                new Field('docs', 'Documentation', FieldType::List, of: [
                    new Field('path', 'Path', FieldType::String, required: true),
                    new Field('title', 'Title', FieldType::String),
                    new Field('body', 'Text', FieldType::Markdown),
                ]),
                new Field('discussions', 'Discussions', FieldType::List, of: [
                    new Field('title', 'Title', FieldType::String, required: true),
                    new Field('number', 'Number', FieldType::Integer),
                    new Field('url', 'Link', FieldType::Url),
                    new Field('author', 'Author', FieldType::String),
                    new Field('category', 'Category', FieldType::String),
                    new Field('created_at', 'Created at', FieldType::Date),
                    new Field('updated_at', 'Updated at', FieldType::Date),
                    new Field('answered', 'Answered', FieldType::Boolean),
                    new Field('body', 'Text', FieldType::Markdown),
                    new Field('comments', 'Comments', FieldType::List, of: [
                        new Field('url', 'Link', FieldType::Url),
                        new Field('author', 'Author', FieldType::String),
                        new Field('created_at', 'Created at', FieldType::Date),
                        new Field('body', 'Text', FieldType::Markdown),
                        new Field('reply_count', 'Replies', FieldType::Integer),
                    ]),
                ]),
                new Field('boards', 'Boards', FieldType::List, help: 'Public boards with what is still open on them.', of: [
                    new Field('title', 'Title', FieldType::String, required: true),
                    new Field('url', 'Link', FieldType::Url),
                    new Field('items', 'Items', FieldType::List, of: [
                        new Field('title', 'Title', FieldType::String, required: true),
                        new Field('kind', 'Kind', FieldType::Enum, options: ['issue', 'pull_request', 'draft']),
                        new Field('number', 'Number', FieldType::Integer),
                        new Field('url', 'Link', FieldType::Url),
                        new Field('status', 'Status', FieldType::String),
                        new Field('author', 'Author', FieldType::String),
                        new Field('by_bot', 'By a bot', FieldType::Boolean),
                        new Field('labels', 'Labels', FieldType::String, multiple: true),
                    ]),
                ]),
                new Field('images', 'Images in the text', FieldType::Media, multiple: true),
                ...CuratedFields::listing(),
            ],
        );
    }
}
