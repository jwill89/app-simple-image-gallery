<?php

namespace Gallery\Structure;

use OpenApi\Spec as OAS;

/**
 * Tag class
 * Represents a tag in the gallery.
 *
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * (e.g. $tag->tag_name), writes go through the fluent setters. private(set)
 * lets PDO's FETCH_CLASS hydrate rows while blocking external mutation.
 */
#[OAS\Schema(schema: 'Tag', description: 'A tag.')]
class Tag extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $tag_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $category_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $tag_name = '';

    public function setTagId(int $tag_id): self
    {
        $this->tag_id = $tag_id;
        return $this;
    }

    public function setCategoryId(int $category_id): self
    {
        $this->category_id = $category_id;
        return $this;
    }

    public function setTagName(string $tag_name): self
    {
        $this->tag_name = $tag_name;
        return $this;
    }
}
