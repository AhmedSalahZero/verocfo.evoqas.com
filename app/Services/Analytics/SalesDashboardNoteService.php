<?php

namespace App\Services\Analytics;

// ══════════════════════════════════════════════════════════════════
//  SalesDashboardNoteService
//
//  CRUD for Analyst Notes on the Sales Dashboard. Kept separate from
//  SalesAnalysisService (that one is pure reporting math with no
//  writes) so the read/write concerns stay cleanly split.
//
//  Ownership rule: only the author may edit or delete their own note
//  — enforced here, not just hidden client-side, since the buttons
//  are only ever hidden client-side per Mahmoud's original design.
//
//  Security (Audit H1, Sept 2026): every note is passed through
//  NoteHtmlSanitizer on save AND on display, so only bold / italic /
//  lists survive — never a script. Display-side cleaning also covers
//  notes saved before the fix.
//
//  @layer    Service
//  @depends  SalesDashboardNote, User
// ══════════════════════════════════════════════════════════════════

use App\Models\SalesDashboardNote;
use App\Support\NoteHtmlSanitizer;
use Illuminate\Support\Collection;

class SalesDashboardNoteService
{
    public function forRange(int $companyId, string $dateFrom, string $dateTo): Collection
    {
        return SalesDashboardNote::with('user:id,name')
            ->where('company_id', $companyId)
            ->where('date_from', $dateFrom)->where('date_to', $dateTo)
            ->latest()->get()
            ->map(fn ($n) => $this->present($n));
    }

    public function create(int $companyId, int $userId, array $data): array
    {
        $note = SalesDashboardNote::create([
            'company_id' => $companyId,
            'user_id'    => $userId,
            'date_from'  => $data['date_from'],
            'date_to'    => $data['date_to'],
            'note'       => NoteHtmlSanitizer::clean($data['note']),
        ]);

        return $this->present($note->fresh('user:id,name'));
    }

    public function update(SalesDashboardNote $note, int $userId, string $body): array
    {
        abort_unless($note->user_id === $userId, 403, 'You can only edit your own note.');
        $note->update(['note' => NoteHtmlSanitizer::clean($body)]);

        return $this->present($note->fresh('user:id,name'));
    }

    public function delete(SalesDashboardNote $note, int $userId): void
    {
        abort_unless($note->user_id === $userId, 403, 'You can only delete your own note.');
        $note->delete();
    }

    private function present(SalesDashboardNote $note): array
    {
        return [
            'id'         => $note->id,
            'author'     => $note->user?->name ?? 'User',
            'author_id'  => $note->user_id,
            'note'       => NoteHtmlSanitizer::clean($note->note),
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }
}
