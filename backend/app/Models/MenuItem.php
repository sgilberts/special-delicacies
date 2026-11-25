<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category_id',
    'name',
    'slug',
    'description',
    'price',
    'image_path',
    'is_available',
    'sort_order',
])]
class MenuItem extends Model
{
    use HasFactory;

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = ['image_url'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_available' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the publicly accessible URL for the image path.
     */
    public function getImagePathAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return asset('storage/' . ltrim($value, '/'));
    }

    /**
     * Accessor for image_url alias.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path;
    }

    /**
     * Category this item belongs to.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    /**
     * Scope to available items.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope ordered by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
