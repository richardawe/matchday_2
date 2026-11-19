<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One request by the agent (API or admin web). Never holds tokens, passwords or request bodies. */
class AgentAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['params' => 'array', 'created_at' => 'datetime'];
}
