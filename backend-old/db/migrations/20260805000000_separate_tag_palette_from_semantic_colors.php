<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Move tag category colours off the Bulma semantic palette and into their own
 * hue namespace.
 *
 * Categories stored state names — Artist was `danger`, Source `warning`,
 * Character `success`, Meta `info` — so a tag chip and a destructive button
 * were painted the same colour, and the tag palette depended on Bulma's
 * semantic classes existing. The UI now renders `tag--<hue>` from its own
 * tokens, which is what lets Bulma be removed later without the tag colours
 * going with it.
 *
 * The mapping preserves how each category currently looks; this is a rename,
 * not a restyle.
 */
final class SeparateTagPaletteFromSemanticColors extends AbstractMigration
{
    /** Old (semantic / Bulma) value => new hue, chosen to look the same. */
    private const array COLOR_MAP = [
        'danger'  => 'rose',
        'warning' => 'amber',
        'success' => 'emerald',
        'info'    => 'sky',
        'link'    => 'sky',
        'primary' => 'emerald',
        'white'   => 'neutral',
        'light'   => 'neutral',
        'dark'    => 'neutral',
        // Extended palette names that no longer exist in the tag scale.
        'purple'  => 'violet',
        'indigo'  => 'violet',
        'pink'    => 'rose',
        'cyan'    => 'sky',
    ];

    /** Reverse of COLOR_MAP for `down()`, back to the closest Bulma name. */
    private const array REVERSE_MAP = [
        'rose'    => 'danger',
        'amber'   => 'warning',
        'emerald' => 'success',
        'sky'     => 'info',
        'violet'  => 'purple',
        'neutral' => 'white',
        'teal'    => 'teal',
        'orange'  => 'orange',
        'lime'    => 'lime',
    ];

    public function up(): void
    {
        foreach (self::COLOR_MAP as $old => $new) {
            $this->execute(sprintf(
                "UPDATE tag_categories SET color = '%s' WHERE color = '%s'",
                $new,
                $old
            ));
        }

        // Anything not covered above (hand-edited rows, future Bulma names)
        // falls back to the neutral outline chip rather than rendering unstyled.
        $valid = "'" . implode("','", array_values(array_unique(self::COLOR_MAP))) . "','teal','orange','lime'";
        $this->execute("UPDATE tag_categories SET color = 'neutral' WHERE color NOT IN ($valid)");

        // The column default was 'white', which is no longer a valid hue.
        $this->table('tag_categories')
            ->changeColumn('color', 'string', ['limit' => 20, 'default' => 'neutral'])
            ->update();
    }

    public function down(): void
    {
        foreach (self::REVERSE_MAP as $new => $old) {
            $this->execute(sprintf(
                "UPDATE tag_categories SET color = '%s' WHERE color = '%s'",
                $old,
                $new
            ));
        }

        $this->table('tag_categories')
            ->changeColumn('color', 'string', ['limit' => 20, 'default' => 'white'])
            ->update();
    }
}
