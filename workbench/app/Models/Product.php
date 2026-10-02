<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Mass assignment is off on purpose (like the apps this plugin is built for): the grid
 * must write attributes explicitly.
 *
 * @property int $id
 * @property string $name
 * @property string $sku
 * @property int|null $shelf_id
 * @property string|null $price
 * @property string|null $cost
 * @property int|null $stock
 * @property string|null $category
 * @property bool $available
 * @property \Illuminate\Support\Carbon|null $released_on
 * @property bool $locked
 * @property bool $archived
 */
class Product extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'cost' => 'decimal:2',
            'stock' => 'integer',
            'available' => 'boolean',
            'locked' => 'boolean',
            'archived' => 'boolean',
            'released_on' => 'date',
        ];
    }
}
