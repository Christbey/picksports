<?php

namespace App\Models;

use App\Enums\DeveloperOrganizationRole;
use Database\Factories\DeveloperOrganizationMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeveloperOrganizationMembership extends Model
{
    /** @use HasFactory<DeveloperOrganizationMembershipFactory> */
    use HasFactory;

    protected $fillable = ['developer_organization_id', 'user_id', 'role'];

    protected function casts(): array
    {
        return ['role' => DeveloperOrganizationRole::class];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(DeveloperOrganization::class, 'developer_organization_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): DeveloperOrganizationMembershipFactory
    {
        return DeveloperOrganizationMembershipFactory::new();
    }
}
