<?php

namespace App\Exceptions\Projects;

// ══════════════════════════════════════════════════════════════════
//  ProjectSiteHasStockException
//
//  Thrown by ProjectService::deactivateProjectSite() when a Project
//  is being closed and its project_site Inventory Location still has
//  stock on hand — Project Module doc §4.3 / §12 Rule 3: "If
//  project_site has remaining stock → hard warning before close is
//  allowed." This is a confirm-to-proceed gate, not a permanent
//  block: ProjectService::changeStatus() accepts a $confirmStockLeft
//  flag: false (default) → this exception stops the close and lists
//  what's still there; true (the user explicitly confirmed) →
//  the close proceeds anyway, stock and all.
//
//  This class was named in the Building Structure Bible's directory
//  layout since v1.5 but never actually created — and the stock
//  check itself was a literal unimplemented `// TODO` (flagged in
//  both the 06 and 07 July 2026 audits: the location was being
//  deactivated unconditionally, with no check at all). Both the
//  check and this exception are built now, using ProductLocationStock
//  — the same table GRN and Stock Transfers already use — as part of
//  the July 2026 audit follow-up.
//
//  @layer    Exception
// ══════════════════════════════════════════════════════════════════

class ProjectSiteHasStockException extends \DomainException
{
}
