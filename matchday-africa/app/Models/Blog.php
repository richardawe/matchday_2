<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class Blog extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'excerpt',
        'content',
        'featured_image',
        'status',
        'published_at',
        'author_name',
        'metadata',
        'view_count'
        ,'creator_profile_id','review_status'
    ];
    public function creatorProfile(){return $this->belongsTo(CreatorProfile::class);}

    protected $casts = [
        'published_at' => 'datetime',
        'metadata' => 'array',
        'view_count' => 'integer'
    ];

    protected $dates = [
        'published_at'
    ];

    /**
     * Boot method to auto-generate slug
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($blog) {
            if (empty($blog->slug)) {
                $blog->slug = Str::slug($blog->title);
            }
        });

        static::updating(function ($blog) {
            // Only regenerate slug if title changed and slug is empty or different
            if ($blog->isDirty('title')) {
                $newSlug = Str::slug($blog->title);
                if ($newSlug !== $blog->slug) {
                    $blog->slug = $newSlug;
                }
            }
        });
    }

    /**
     * Scope for published blogs
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
                    ->where('published_at', '<=', now());
    }

    /**
     * Scope for featured blogs
     */
    public function scopeFeatured($query)
    {
        return $query->published()->orderBy('view_count', 'desc');
    }

    /**
     * Get the excerpt or generate from content
     */
    public function getExcerptAttribute($value)
    {
        if ($value) {
            return $value;
        }
        
        return Str::limit(strip_tags($this->content), 150);
    }

    /**
     * Get the featured image URL
     */
    public function getFeaturedImageUrlAttribute()
    {
        if ($this->featured_image) {
            if (Str::startsWith($this->featured_image, ['http://', 'https://'])) {
                return $this->featured_image;
            }

            $path = 'blog-images/'.basename($this->featured_image);
            if (Storage::disk('public')->exists($path)) {
                return route('blogs.image', ['filename' => basename($this->featured_image)]);
            }

            return asset('images/matchday-africa-logo.svg');
        }
        
        return asset('images/default-blog-image.jpg');
    }

    /**
     * Check if blog is published
     */
    public function isPublished()
    {
        return $this->status === 'published' && 
               $this->published_at && 
               $this->published_at <= now();
    }

    /**
     * Publish the blog
     */
    public function publish()
    {
        $this->update([
            'status' => 'published',
            'published_at' => now()
        ]);
    }

    /**
     * Unpublish the blog
     */
    public function unpublish()
    {
        $this->update([
            'status' => 'draft',
            'published_at' => null
        ]);
    }

    /**
     * Increment view count
     */
    public function incrementViewCount()
    {
        $this->increment('view_count');
    }

    /**
     * Get formatted published date
     */
    public function getFormattedPublishedDateAttribute()
    {
        if ($this->published_at) {
            return $this->published_at->format('M d, Y');
        }
        
        return 'Not published';
    }

    /**
     * Get reading time estimate
     */
    public function getReadingTimeAttribute()
    {
        $words = str_word_count(strip_tags($this->content));
        $minutes = ceil($words / 200); // Average reading speed
        
        return $minutes . ' min read';
    }
}
