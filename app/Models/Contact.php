<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $phone
 * @property string $subject
 * @property string $message
 * @property string $status
 */
#[Fillable(['name', 'email', 'phone', 'subject', 'message', 'status'])]
class Contact extends Model
{
    /**
     * List of allowed contact subjects (technical values).
     *
     * @var array<int, string>
     */
    public const SUBJECTS = [
        'information',
        'partnership',
        'contribution',
        'other',
    ];
}