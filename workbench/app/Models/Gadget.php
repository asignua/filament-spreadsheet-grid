<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A model on a connection other than the default one.
 *
 * @property int $id
 * @property string $name
 */
class Gadget extends Model
{
    protected $connection = 'secondary';

    protected $guarded = ['*'];

    public $timestamps = false;
}
