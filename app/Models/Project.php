<?php

namespace App\Models;

use App\Concerns\GeneratesUniqueProjectSlugs;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Project extends Model implements HasMedia
{
    use GeneratesUniqueProjectSlugs, HasFactory, InteractsWithMedia;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = static::generateUniqueProjectSlug($project->name);
            }
        });

        static::updating(function (Project $project) {
            if ($project->isDirty('name')) {
                $project->slug = static::generateUniqueProjectSlug($project->name, $project->id);
            }
        });
    }

    protected $fillable = [
        'team_id',
        'name',
        'slug',
        'api_token',
        'url',
        'uptime_check_interval',
        'last_uptime_check_at',
        'last_uptime_status',
        'settings',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'api_token',
        'settings',
    ];

    protected $appends = ['logo_url'];

    public function getLogoUrlAttribute()
    {
        $media = $this->getFirstMedia('logo');

        return $media
            ? $media->getUrl()
            : 'https://ui-avatars.com/api/?name='.urlencode($this->name).'&color=7F9CF5&background=EBF4FF';
    }

    protected $casts = [
        'settings' => 'array',
        'last_uptime_check_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(Record::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(Issue::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(Integration::class);
    }

    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    public function thresholds(): HasMany
    {
        return $this->hasMany(Threshold::class);
    }

    public function uptimeChecks(): HasMany
    {
        return $this->hasMany(UptimeCheck::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(Heartbeat::class);
    }

    protected function teamSlug(): Attribute
    {
        return Attribute::make(
            get: function () {
                $this->loadMissing('team:slug');
                return $this->team?->slug ?? 'projects';
            }
        );
    }

}
