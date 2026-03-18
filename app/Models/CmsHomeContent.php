<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class CmsHomeContent extends Model
{
    protected $table = 'cms_home_content';

    protected $fillable = [
        'section_key',
        'title',
        'subtitle',
        'content',
        'image_path',
        'link_url',
        'link_text',
        'extra_data',
        'status',
        'sort_order',
    ];

    protected $casts = [
        'extra_data' => 'array',
        'status' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::saved(function ($model) {
            Cache::forget('cms_home_content_all');
            Cache::forget('cms_home_content_' . $model->section_key);
        });

        static::deleted(function ($model) {
            Cache::forget('cms_home_content_all');
            Cache::forget('cms_home_content_' . $model->section_key);
        });
    }

    public static function getByKey($key)
    {
        return Cache::remember('cms_home_content_' . $key, 3600, function () use ($key) {
            return static::where('section_key', $key)->where('status', 1)->first();
        });
    }

    public static function getAllActive()
    {
        return Cache::remember('cms_home_content_all', 3600, function () {
            return static::where('status', 1)->orderBy('id')->get()->keyBy('section_key');
        });
    }

    /**
     * Get the image URL using local public storage
     */
    public function getImageUrlAttribute()
    {
        if (!$this->image_path) {
            return null;
        }

        // Force use of public disk URL instead of default S3
        return asset('storage/' . $this->image_path);
    }
}
