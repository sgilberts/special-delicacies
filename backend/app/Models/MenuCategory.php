<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'slug', 'description', 'sort_order', 'is_active'])]
class MenuCategory extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Menu items belonging to this category.
     */
    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id')->orderBy('sort_order');
    }

    /**
     * Available items belonging to this category.
     */
    public function availableItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'category_id')
            ->where('is_available', true)
            ->orderBy('sort_order');
    }

    /**
     * Scope to active categories.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope ordered by sort order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
