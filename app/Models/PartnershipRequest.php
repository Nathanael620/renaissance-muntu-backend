<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string|null $organization
 * @property string $email
 * @property string|null $phone
 * @property string $partnership_type
 * @property string|null $message
 * @property string $status
 */
#[Fillable([
    'name',
    'organization',
    'email',
    'phone',
    'partnership_type',
    'message',
    'status',
])]
class PartnershipRequest extends Model
{
    /**
     * List of partnership types offered by the support form.
     *
     * @var array<int, string>
     */
    public const TYPES = [
        'sponsorship',
        'collaborative_project',
        'media_communication',
        'research_academic',
        'event_training',
        'other',
    ];
}
