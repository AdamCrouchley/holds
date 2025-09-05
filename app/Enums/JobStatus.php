<?php

namespace App\Enums;

enum JobStatus: string
{
    case Pending   = 'pending';    // created, not yet authorized
    case Active    = 'active';     // hold authorized / renewing
    case Captured  = 'captured';   // final charge taken
    case Released  = 'released';   // hold released without capture
    case Cancelled = 'cancelled';  // explicitly cancelled
    case Failed    = 'failed';     // auth/capture error
}
