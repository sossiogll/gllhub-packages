<?php

declare(strict_types=1);

namespace Gll\Hub\Portfolio\Types;

use Gll\Hub\Contracts\Content\ContentType;
use Gll\Hub\Contracts\Content\ContentTypeDefinition;

/** A standalone page (about, now). The core's title and body are all it needs. */
final class Page implements ContentType
{
    public const KEY = 'page';

    public static function definition(): ContentTypeDefinition
    {
        return new ContentTypeDefinition(
            key: self::KEY,
            label: 'Page',
            pluralLabel: 'Pages',
            fields: [],
            commentable: false,
            likeable: false,
        );
    }
}
