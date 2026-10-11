<?php

namespace App\Exceptions\Projects;

// ══════════════════════════════════════════════════════════════════
//  ProjectHasOpenSOsException
//
//  Thrown by ProjectService::changeStatus() when moving a Project to
//  'closed' while it still has Sales Orders in a status other than
//  'closed' or 'cancelled' — Project Module doc §12 Rule 13: "Closing
//  a project with open Sales Orders is blocked — all SOs must be
//  closed first." Hard block, not a warning.
//
//  This class was named in the Building Structure Bible's directory
//  layout since v1.5 but never actually created —
//  Project::isCloseable() already implements the check correctly,
//  but ProjectService was throwing a generic \DomainException with
//  the right message instead of this named class. Created and wired
//  in as part of the July 2026 audit follow-up.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class ProjectHasOpenSOsException extends \DomainException
{
}
