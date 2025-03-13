<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Relations\Pivot;

class GameTypeProduct extends Pivot {
     protected $fillable = ['game_type_id', 'product_id', 'image', 'rate']; // Added 'rate'
}