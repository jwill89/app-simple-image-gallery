<?php

namespace Gallery\Structure;

use OpenApi\Spec as OAS;

/**
 * TagCategory class
 * Represents a tag category in the gallery.
 *
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * (e.g. $category->category_name), writes go through the fluent setters.
 * private(set) lets PDO's FETCH_CLASS hydrate rows while blocking external
 * mutation.
 */
#[OAS\Schema(schema: 'TagCategory', description: 'A tag category (color, shortcode, sort order).')]
class TagCategory extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $category_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $category_name = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Tag palette hue. Display only — never a semantic state name.'))]
    public private(set) string $color = 'neutral';
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $description = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $sort_order = 0;

    public function setCategoryId(int $category_id): self
    {
        $this->category_id = $category_id;
        return $this;
    }

    public function setCategoryName(string $category_name): self
    {
        $this->category_name = $category_name;
        return $this;
    }

    public function setColor(string $color): self
    {
        $this->color = $color;
        return $this;
    }

    public function setDescription(string $description): self
    {
        $this->description = $description;
        return $this;
    }

    public function setSortOrder(int $sort_order): self
    {
        $this->sort_order = $sort_order;
        return $this;
    }
}
