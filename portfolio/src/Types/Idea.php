<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Types;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;
use Gll\Hub\Contracts\Content\Field;
use Gll\Hub\Contracts\Content\FieldType;

/** A half-baked idea and how far it got. */
final class Idea implements ContentType
{
    public const KEY = 'idea';

    public const STAGES = ['seed', 'growing', 'shipped'];

    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            key: self::KEY,
            label: 'Idea',
            pluralLabel: 'Ideas',
            fields: [
                new Field('stage', 'Stage', FieldType::Enum, required: true, options: self::STAGES),
                new Field('became', 'Became the project', FieldType::Reference, references: [Project::KEY]),
                ...CuratedFields::listing(),
            ],
            routePrefix: 'ideas',
        );
    }
}
