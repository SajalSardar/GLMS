<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TeacherProfile extends Model
{
    use Auditable, SoftDeletes, TenantScoped;

    protected $fillable = [
        'user_id',
        'school_id',
        'district_id',
        'employee_id',
        'designation',
        'subject_specialization',
        'qualification',
        'joining_date',
        'nid_number',
        'phone',
        'blood_group',
        'present_address',
        'permanent_address',
        'photo_path',
    ];

    protected function casts(): array
    {
        return [
            'joining_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }
}
