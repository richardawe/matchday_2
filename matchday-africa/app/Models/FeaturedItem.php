<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** A slideshow or radio edition pinned to the front of the "As seen on TikTok" row ("slideshow:<id>", "radio:<id>"). */
class FeaturedItem extends Model
{
    protected $fillable = ['subject', 'sort_order'];

    protected $casts = ['sort_order' => 'integer'];

    public static function ready(): bool
    {
        return Schema::hasTable('featured_items');
    }
}
