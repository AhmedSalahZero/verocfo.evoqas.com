<?php

namespace App\Services\Analytics;

// ══════════════════════════════════════════════════════════════════
//  ExpenseDashboardNoteService
//
//  CRUD for Analyst Notes on the Expense Dashboard. Mirrors
//  SalesDashboardNoteService exactly (see that class for reasoning
//  on the ownership rule and the read/write split) — kept as its
//  own small class rather than a shared generic one so each
//  dashboard's note logic stays easy to read on its own.
//
//  Security (Audit H1, Sept 2026): every note is passed through
//  NoteHtmlSanitizer on save AND on display, so only bold / italic /
//  lists survive — never a script. Display-side cleaning also covers
//  notes saved before the fix.
//
//  @layer    Service
//  @depends  ExpenseDashboardNote, User
// ══════════════════════════════════════════════════════════════════

use App\Models\ExpenseDashboardNote;
use App\Support\NoteHtmlSanitizer;
use Illuminate\Support\Collection;

class ExpenseDashboardNoteService
{
    public function forRange(int $companyId, string $dateFrom, string $dateTo): Collection
    {
        return ExpenseDashboardNote::with('user:id,name')
            ->where('company_id', $companyId)
            ->where('date_from', $dateFrom)->where('date_to', $dateTo)
            ->latest()->get()
            ->map(fn ($n) => $this->present($n));
    }

    public function create(int $companyId, int $userId, array $data): array
    {
        $note = ExpenseDashboardNote::create([
            'company_id' => $companyId,
            'user_id'    => $userId,
            'date_from'  => $data['date_from'],
            'date_to'    => $data['date_to'],
            'note'       => NoteHtmlSanitizer::clean($data['note']),
        ]);

        return $this->present($note->fresh('user:id,name'));
    }

    public function update(ExpenseDashboardNote $note, int $userId, string $body): array
    {
        abort_unless($note->user_id === $userId, 403, 'You can only edit your own note.');
        $note->update(['note' => NoteHtmlSanitizer::clean($body)]);

        return $this->present($note->fresh('user:id,name'));
    }

    public function delete(ExpenseDashboardNote $note, int $userId): void
    {
        abort_unless($note->user_id === $userId, 403, 'You can only delete your own note.');
        $note->delete();
    }

    private function present(ExpenseDashboardNote $note): array
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
